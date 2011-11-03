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
 * Cohort enrolment plugin.
 *
 * @package    enrol
 * @subpackage jwc
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Cohort enrolment plugin implementation.
 * @author Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_jwc_plugin extends enrol_plugin {
    /**
     * Returns localised name of enrol instance
     *
     * @param object $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance)) {
            $enrol = $this->get_name();
            return get_string('pluginname', 'enrol_'.$enrol);
        } else if (empty($instance->name)) {
            $enrol = $this->get_name();
            if ($role = $DB->get_record('role', array('id'=>$instance->roleid))) {
                $role = role_get_name($role, get_context_instance(CONTEXT_COURSE, $instance->courseid));
            } else {
                $role = get_string('error');
            }

            return get_string('pluginname', 'enrol_'.$enrol) . ' (' . format_string($instance->customchar1.'-'.$instance->customchar2) . ')';
        } else {
            return format_string($instance->name);
        }
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        if (!$this->can_add_new_instances($courseid)) {
            return NULL;
        }
        // multiple instances supported - multiple parent courses linked
        return new moodle_url('/enrol/jwc/addinstance.php', array('id'=>$courseid));
    }

    /**
     * Given a courseid this function returns true if the user is able to enrol or configure jwc
     *
     * @param int $courseid
     * @return bool
     */
    protected function can_add_new_instances($courseid) {
        global $DB, $CFG;

        $coursecontext = get_context_instance(CONTEXT_COURSE, $courseid);
        if (!has_capability('moodle/course:enrolconfig', $coursecontext) or !has_capability('enrol/jwc:config', $coursecontext)) {
            return false;
        }

        require_once("$CFG->dirroot/enrol/jwc/locallib.php");
        if (!enrol_jwc_get_cas_teachers($courseid)) {
            // 课程中必须有使用cas认证的教师
            return false;
        }

        return true;
    }

    /**
     * Called for all enabled enrol plugins that returned true from is_cron_required().
     * @return void
     */
    public function cron() {
        global $DB, $CFG;

        // purge all roles if jwc sync disabled, those can be recreated later here in cron
        if (!enrol_is_enabled('jwc')) {
            role_unassign_all(array('component'=>'jwc_enrol'));
            return;
        }

        // 管理员可以设定清除所有选课
        if ($this->get_config('unenrolall')) {
            $instances = $DB->get_records('enrol', array('enrol' => 'jwc'));
            foreach ($instances as $instance) {
                //first unenrol all users
                $participants = $DB->get_recordset('user_enrolments', array('enrolid'=>$instance->id));
                foreach ($participants as $participant) {
                    $this->unenrol_user($instance, $participant->userid);
                }
                $participants->close();

                // now clean up all remainders that were not removed correctly
                $DB->delete_records('role_assignments', array('itemid'=>$instance->id, 'component'=>'jwc'));
                $DB->delete_records('user_enrolments', array('enrolid'=>$instance->id));
            }

            $this->set_config('unenrolall', 0);
            return;
        }

        // 暂时禁用cron。将来通过是否有数据更改来决定cron同步周期
        // require_once("$CFG->dirroot/enrol/jwc/locallib.php");
        // enrol_jwc_sync();
    }

    /**
     * Called after updating/inserting course.
     *
     * @param bool $inserted true if course just inserted
     * @param object $course
     * @param object $data form data
     * @return void
     */
    public function course_updated($inserted, $course, $data) {
        global $CFG, $DB;

        if (!$inserted) {
            $instance = $DB->get_record('enrol', array('enrol' => 'jwc', 'courseid' => $course->id), '*', IGNORE_MULTIPLE);
            if (!empty($course->idnumber)) {
                if ($instance) {
                    $instance->customchar1 = $course->idnumber;
                    $DB->update_record('enrol', $instance);
                } else {
                    $this->add_instance($course, array('customchar1'=>$course->idnumber, $this->get_config('roleid')));
                }
            } else if ($instance) { // remove old instance
                $this->delete_instance($instance);
            }

            // sync jwc enrols
            require_once("$CFG->dirroot/enrol/jwc/locallib.php");
            enrol_jwc_sync($course->id);
        } else {
            if (!empty($course->idnumber)) {
                $this->add_instance($course, array('customchar1'=>$course->idnumber, $this->get_config('roleid')));
            }
        }
    }
}

/**
 * Indicates API features that the enrol plugin supports.
 *
 * @param string $feature
 * @return mixed True if yes (some features may use other values)
 */
function enrol_jwc_supports($feature) {
    switch($feature) {
        case ENROL_RESTORE_TYPE: return ENROL_RESTORE_EXACT;

        default: return null;
    }
}

