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


/**
 * Event handler for jwc enrolment plugin.
 *
 * We try to keep everything in sync via listening to events,
 * it may fail sometimes, so we always do a full sync in cron too.
 */
class enrol_jwc_handler {
    public function member_added($ca) {
        global $DB;

        if (!enrol_is_enabled('jwc')) {
            return true;
        }

        // does anything want to sync with this parent?
        //TODO: add join to role table to make sure that roleid actually exists
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$ca->jwcid, 'enrol'=>'jwc'), 'id ASC')) {
            return true;
        }

        $plugin = enrol_get_plugin('jwc');
        foreach ($enrols as $enrol) {
            // no problem if already enrolled
            $plugin->enrol_user($enrol, $ca->userid, $enrol->roleid);
        }

        return true;
    }

    public function member_removed($ca) {
        global $DB;

        // does anything want to sync with this parent?
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$ca->jwcid, 'enrol'=>'jwc'), 'id ASC')) {
            return true;
        }

        $plugin = enrol_get_plugin('jwc');
        foreach ($enrols as $enrol) {
            // no problem if already enrolled
            $plugin->unenrol_user($enrol, $ca->userid);
        }

        return true;
    }

    public function deleted($jwc) {
        global $DB;

        // does anything want to sync with this parent?
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$jwc->id, 'enrol'=>'jwc'), 'id ASC')) {
            return true;
        }

        $plugin = enrol_get_plugin('jwc');
        foreach ($enrols as $enrol) {
            $plugin->delete_instance($enrol);
        }

        return true;
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

/**
 * Enrols all of the users in a jwc through a manual plugin instance.
 *
 * In order for this to succeed the course must contain a valid manual
 * enrolment plugin instance that the user has permission to enrol users through.
 *
 * @global moodle_database $DB
 * @param course_enrolment_manager $manager
 * @param int $jwcid
 * @param int $roleid
 * @return int
 */
function enrol_jwc_enrol_all_users(course_enrolment_manager $manager, $jwcid, $roleid) {
    global $DB;
    $context = $manager->get_context();
    require_capability('moodle/course:enrolconfig', $context);

    $instance = false;
    $instances = $manager->get_enrolment_instances();
    foreach ($instances as $i) {
        if ($i->enrol == 'manual') {
            $instance = $i;
            break;
        }
    }
    $plugin = enrol_get_plugin('manual');
    if (!$instance || !$plugin || !$plugin->allow_enrol($instance) || !has_capability('enrol/'.$plugin->get_name().':enrol', $context)) {
        return false;
    }
    $sql = "SELECT com.userid
              FROM {jwc_members} com
         LEFT JOIN (
                SELECT *
                FROM {user_enrolments} ue
                WHERE ue.enrolid = :enrolid
                 ) ue ON ue.userid=com.userid
             WHERE com.jwcid = :jwcid AND ue.id IS NULL";
    $params = array('jwcid' => $jwcid, 'enrolid' => $instance->id);
    $rs = $DB->get_recordset_sql($sql, $params);
    $count = 0;
    foreach ($rs as $user) {
        $count++;
        $plugin->enrol_user($instance, $user->userid, $roleid);
    }
    $rs->close();
    return $count;
}

/**
 * Gets all the jwcs the user is able to view.
 *
 * @global moodle_database $DB
 * @return array
 */
function enrol_jwc_get_jwcs(course_enrolment_manager $manager) {
    global $DB;
    $context = $manager->get_context();
    $jwcs = array();
    $instances = $manager->get_enrolment_instances();
    $enrolled = array();
    foreach ($instances as $instance) {
        if ($instance->enrol == 'jwc') {
            $enrolled[] = $instance->customint1;
        }
    }
    list($sqlparents, $params) = $DB->get_in_or_equal(get_parent_contexts($context));
    $sql = "SELECT id, name, contextid
              FROM {jwc}
             WHERE contextid $sqlparents
          ORDER BY name ASC";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $c) {
        $context = get_context_instance_by_id($c->contextid);
        if (!has_capability('moodle/jwc:view', $context)) {
            continue;
        }
        $jwcs[$c->id] = array(
            'jwcid'=>$c->id,
            'name'=>format_string($c->name),
            'users'=>$DB->count_records('jwc_members', array('jwcid'=>$c->id)),
            'enrolled'=>in_array($c->id, $enrolled)
        );
    }
    $rs->close();
    return $jwcs;
}