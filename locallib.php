<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local stuff for jwc enrolment plugin.
 *
 * @package    enrol
 * @subpackage jwc
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/enrol/locallib.php');

class jwc_helper {
    static public function is_valid_coursenumber($coursenumber, $semester = '') {
        //jwc_helper::get_courses($coursenumber, $semester);
        return true;
    }

    /**
     * 得到编号为coursenumber的所有课程信息
     *
     * 返回数组
     * 如编号正确但无对应课程，返回空数组
     * 如编号不正确，返回false
     */
    static public function get_courses($coursenumber, $semester = '') {
        $params = array();
        if (empty($semester)) {
            $params['xq'] = get_config('enrol_jwc', 'semester');
        } else {
            $params['xq'] = $semester;
        }
        $params['id'] = $coursenumber;
        echo jwc_helper::access('http://xscj.hit.edu.cn/hitjwgl/lxw/getinfoD.asp', $params);
        die;
    }

    static protected function access($url_base, $params) {
        if (empty($params)) {
            return false;
        }

        $param = '';
        foreach ($params as $var => $value) {
            if (empty($param)) {
                $param = "$var=$value";
            } else {
                $param .= "&$var=$value";
            }
        }

        $param = textlib_get_instance()->convert($param, 'UTF-8', 'GB18030');

        // 添加数字签名
        $sign = md5("jwc{$param}lxw");
        $param .= "&sign=$sign";

        $url = $url_base.'?'.$param;
        echo $url;
        return download_file_content($url);
    }
}

/**
 * Sync all jwc course links.
 * @param int $courseid one course, empty mean all
 * @return void
 */
function enrol_jwc_sync($courseid = NULL) {
    global $CFG, $DB;

    // unfortunately this may take a long time
    @set_time_limit(0); //if this fails during upgrade we can continue from cron, no big deal

    $jwc = enrol_get_plugin('jwc');

    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";

    // iterate through all not enrolled yet users
    if (enrol_is_enabled('jwc')) {
        $params = array();
        $onecourse = "";
        if ($courseid) {
            $params['courseid'] = $courseid;
            $onecourse = "AND e.courseid = :courseid";
        }
        $sql = "SELECT cm.userid, e.id AS enrolid
                  FROM {jwc_members} cm
                  JOIN {enrol} e ON (e.customint1 = cm.jwcid AND e.status = :statusenabled AND e.enrol = 'jwc' $onecourse)
             LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = cm.userid)
                 WHERE ue.id IS NULL";
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['courseid'] = $courseid;
        $rs = $DB->get_recordset_sql($sql, $params);
        $instances = array(); //cache
        foreach($rs as $ue) {
            if (!isset($instances[$ue->enrolid])) {
                $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
            }
            $jwc->enrol_user($instances[$ue->enrolid], $ue->userid);
        }
        $rs->close();
        unset($instances);
    }

    // unenrol as necessary - ignore enabled flag, we want to get rid of all
    $sql = "SELECT ue.userid, e.id AS enrolid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'jwc' $onecourse)
         LEFT JOIN {jwc_members} cm ON (cm.jwcid  = e.customint1 AND cm.userid = ue.userid)
             WHERE cm.id IS NULL";
    //TODO: this may use a bit of SQL optimisation
    $rs = $DB->get_recordset_sql($sql, array('courseid'=>$courseid));
    $instances = array(); //cache
    foreach($rs as $ue) {
        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $jwc->unenrol_user($instances[$ue->enrolid], $ue->userid);
    }
    $rs->close();
    unset($instances);

    // now assign all necessary roles
    if (enrol_is_enabled('jwc')) {
        $sql = "SELECT e.roleid, ue.userid, c.id AS contextid, e.id AS itemid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'jwc' AND e.status = :statusenabled $onecourse)
                  JOIN {context} c ON (c.instanceid = e.courseid AND c.contextlevel = :coursecontext)
             LEFT JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.userid = ue.userid AND ra.itemid = e.id AND ra.component = 'enrol_jwc' AND e.roleid = ra.roleid)
                 WHERE ra.id IS NULL";
        $params = array();
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['coursecontext'] = CONTEXT_COURSE;
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach($rs as $ra) {
            role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_jwc', $ra->itemid);
        }
        $rs->close();
    }

    // remove unwanted roles - include ignored roles and disabled plugins too
    $onecourse = $courseid ? "AND c.instanceid = :courseid" : "";
    $sql = "SELECT ra.roleid, ra.userid, ra.contextid, ra.itemid
              FROM {role_assignments} ra
              JOIN {context} c ON (c.id = ra.contextid AND c.contextlevel = :coursecontext $onecourse)
         LEFT JOIN (SELECT e.id AS enrolid, e.roleid, ue.userid
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'jwc')
                   ) x ON (x.enrolid = ra.itemid AND ra.component = 'enrol_jwc' AND x.roleid = ra.roleid AND x.userid = ra.userid)
             WHERE x.userid IS NULL AND ra.component = 'enrol_jwc'";
    $params = array('coursecontext' => CONTEXT_COURSE, 'courseid' => $courseid);

    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_jwc', $ra->itemid);
    }
    $rs->close();

}

