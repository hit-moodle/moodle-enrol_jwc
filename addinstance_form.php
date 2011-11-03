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
 * Adds instance form
 *
 * @package    enrol
 * @subpackage jwc
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

class enrol_jwc_addinstance_form extends moodleform {
    function definition() {
        global $CFG, $DB;

        $mform  = $this->_form;
        $course = $this->_customdata;
        $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);

        $enrol = enrol_get_plugin('jwc');

        $roles = get_assignable_roles($coursecontext);
        $roles = array_reverse($roles, true); // descending default sortorder

        $mform->addElement('header','general', get_string('pluginname', 'enrol_jwc'));

        $mform->addElement('html', '与教务管理系统的学生选课同步。只有使用HITID登录的教师和学生才能享用此功能');

        $mform->addElement('text', 'coursenumber', get_string('coursenumber', 'enrol_jwc'));
        $mform->setType('coursenumber', PARAM_ALPHANUM);
        $mform->addHelpButton('coursenumber', 'coursenumber', 'enrol_jwc');
        $mform->addRule('coursenumber', get_string('required'), 'required', null, 'client');

        $mform->addElement('hidden', 'roleid', $enrol->get_config('roleid'));
        $mform->setType('roleid', PARAM_INT);

        $mform->addElement('hidden', 'id', null);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('addinstance', 'enrol'));

        $this->set_data(array('id'=>$course->id));
    }

    function validation($data, $files) {
        global $DB, $CFG, $COURSE;

        $errors = parent::validation($data, $files);

        require_once("$CFG->dirroot/enrol/jwc/locallib.php");

        $jwc = new jwc_helper();
        if (!$jwc->get_all_courses($data['coursenumber'], get_config('enrol_jwc', 'semester'))) {
            $errors['coursenumber'] = '在教务处查询此课程编号出错：'.$jwc->errormsg;
        }

        if ($DB->record_exists('enrol', array('enrol'=>'jwc', 'courseid'=>$COURSE->id, 'customchar1'=>$data['coursenumber']))) {
            $errors['coursenumber'] = '本课程已有一个教务处同步选课实例使用此课程编号';
        }
        return $errors;
    }
}
