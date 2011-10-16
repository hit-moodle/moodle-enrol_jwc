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
    /**
     * 得到编号为coursenumber的所有课程信息
     *
     * 如果$semester为空，表示访问当前学期
     * 返回数组，成员是课程对象
     * 如编号正确但无对应课程，返回空数组
     * 如遇错误，返回false，错误信息存入$error
     */
    static public function get_courses($coursenumber, $semester, &$error) {
        $params = array();
        if (empty($semester)) {
            $params['xq'] = get_config('enrol_jwc', 'semester');
        } else {
            $params['xq'] = $semester;
        }
        $params['id'] = $coursenumber;
        $jwcstr = jwc_helper::access('http://xscj.hit.edu.cn/hitjwgl/lxw/getinfoD.asp', $params);

        if ($error = jwc_helper::get_error($jwcstr)) {
            return false;
        }

        $info = new SimpleXMLElement($jwcstr);
        $courses = array();
        foreach ($info->course->item as $item) {
            $courses[] = $item;
        }

        return $courses;
    }

    static protected function access($url_base, $params) {
        if (empty($params)) {
            return false;
        }

        $param = '';
        foreach ($params as $var => $value) {
            //$value = textlib_get_instance()->convert($value, 'UTF-8', 'GBK');
            $value = urlencode($value);
            if (empty($param)) {
                $param = "$var=$value";
            } else {
                $param .= "&$var=$value";
            }
        }

        // 添加数字签名
        $prefix = get_config('enrol_jwc', 'signprefix');
        $suffix = get_config('enrol_jwc', 'signsuffix');
        $sign = md5($prefix.$param.$suffix);
        $param .= "&sign=$sign";

        $url = $url_base.'?'.$param;
        return download_file_content($url);
    }

    static protected function get_error($jwcstr) {
        $info = new SimpleXMLElement($jwcstr);
        if ($info->retu->flag == 1) {
            return false; // no error
        }
        return $info->retu->errorinfo;
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
    $teacherroleid = $jwc->get_config('teacherroleid');

    if (enrol_is_enabled('jwc')) {
        $params = array();
        $onecourse = "";
        if ($courseid) {
            $params['courseid'] = $courseid;
            $onecourse = "AND courseid = :courseid";
        }

        $select = "enrol = :jwc AND status = :status $onecourse";
        $params['jwc'] = 'jwc';
        $params['status'] = ENROL_INSTANCE_ENABLED;
        $instances = $DB->get_records_select('enrol', $select, $params);
        foreach ($instances as $instance) {
            $context = get_context_instance(CONTEXT_COURSE, $instance->courseid);

            // 课程必须有cas认证的教师
            $where = 'auth = :auth AND id IN (SELECT userid FROM {role_assignments} WHERE roleid = :roleid AND contextid = :contextid )';
            $teachers = $DB->get_records_select('user', $where, array('auth' => 'cas', 'roleid' => $teacherroleid, 'contextid' => $context->id));
            if (empty($teachers)) {
                $DB->set_field('enrol', 'customchar2', '此课程没有使用HITID的教师', array('id' => $instance->id));
                continue;
            }

            // 从教务处获取所有使用该编号的课程
            $error = '';
            $courses = jwc_helper::get_courses($instance->customchar1, '', $error);
            if (!$courses) { // 出错
                $DB->set_field('enrol', 'customchar2', $error, array('id' => $instance->id));
                continue; // skip this instance
            }

            // 匹配教师姓名，找出可同步的课程
            $xkids = array();
            foreach ($courses as $course) {
                foreach ($teachers as $teacher) {
                    if ($teacher->lastname == $course->jsname) {
                        $xkids[] = $course->xkid;
                        break;  // 有一个教师与当前课匹配，就够了
                    }
                }
            }
            if (empty($xkids)) {
                $msg = '没有可同步的课程';
            } else {
                $course = reset($courses);
                $msg = $course->kcname.'-'.implode(',', $xkids);
            }
            $DB->set_field('enrol', 'customchar2', $msg, array('id' => $instance->id));

            // 开始同步
            foreach ($xkids as $xkid) {
                enrol_jwc_sync_xk($xkid, $instance);
            }
        }
    }
}

function enrol_jwc_sync_xk($xkid, $enrol_instance) {

    $jwc = enrol_get_plugin('jwc');
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

