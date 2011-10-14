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

        $jwcs = array('' => get_string('choosedots'));
        list($sqlparents, $params) = $DB->get_in_or_equal(get_parent_contexts($coursecontext));
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
            $jwcs[$c->id] = format_string($c->name);
        }
        $rs->close();

        $roles = get_assignable_roles($coursecontext);
        $roles = array_reverse($roles, true); // descending default sortorder

        $mform->addElement('header','general', get_string('pluginname', 'enrol_jwc'));

        $mform->addElement('select', 'jwcid', get_string('jwc', 'jwc'), $jwcs);
        $mform->addRule('jwcid', get_string('required'), 'required', null, 'client');

        $mform->addElement('select', 'roleid', get_string('role'), $roles);
        $mform->addRule('roleid', get_string('required'), 'required', null, 'client');
        $mform->setDefault('roleid', $enrol->get_config('roleid'));

        $mform->addElement('hidden', 'id', null);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('addinstance', 'enrol'));

        $this->set_data(array('id'=>$course->id));
    }

    //TODO: validate duplicate role-jwc does not exist
}
