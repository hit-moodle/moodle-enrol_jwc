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
 * This file processes AJAX enrolment actions and returns JSON for the jwc plugin
 *
 * The general idea behind this file is that any errors should throw exceptions
 * which will be returned and acted upon by the calling AJAX script.
 *
 * @package    enrol
 * @subpackage jwc
 * @copyright  2011 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require('../../config.php');
require_once($CFG->dirroot.'/enrol/locallib.php');
require_once($CFG->dirroot.'/enrol/jwc/locallib.php');
require_once($CFG->dirroot.'/group/lib.php');

// Must have the sesskey
$id      = required_param('id', PARAM_INT); // course id
$action  = required_param('action', PARAM_ACTION);

$PAGE->set_url(new moodle_url('/enrol/jwc/ajax.php', array('id'=>$id, 'action'=>$action)));

$course = $DB->get_record('course', array('id'=>$id), '*', MUST_EXIST);
$context = get_context_instance(CONTEXT_COURSE, $course->id, MUST_EXIST);

if ($course->id == SITEID) {
    throw new moodle_exception('invalidcourse');
}

require_login($course);
require_capability('moodle/course:enrolreview', $context);
require_sesskey();

echo $OUTPUT->header(); // send headers

$manager = new course_enrolment_manager($PAGE, $course);

$outcome = new stdClass;
$outcome->success = true;
$outcome->response = new stdClass;
$outcome->error = '';

switch ($action) {
    case 'getassignable':
        $otheruserroles = optional_param('otherusers', false, PARAM_BOOL);
        $outcome->response = array_reverse($manager->get_assignable_roles($otheruserroles), true);
        break;
    case 'getdefaultjwcrole': //TODO: use in ajax UI MDL-24280
        $jwcenrol = enrol_get_plugin('jwc');
        $outcome->response = $jwcenrol->get_config('roleid');
        break;
    case 'getjwcs':
        require_capability('moodle/course:enrolconfig', $context);
        $outcome->response = enrol_jwc_get_jwcs($manager);
        break;
    case 'enroljwc':
        require_capability('moodle/course:enrolconfig', $context);
        require_capability('enrol/jwc:config', $context);
        $roleid = required_param('roleid', PARAM_INT);
        $jwcid = required_param('jwcid', PARAM_INT);
        
        $roles = $manager->get_assignable_roles();
        $jwcs = enrol_jwc_get_jwcs($manager);
        if (!array_key_exists($jwcid, $jwcs) || !array_key_exists($roleid, $roles)) {
            throw new enrol_ajax_exception('errorenroljwc');
        }
        $enrol = enrol_get_plugin('jwc');
        $enrol->add_instance($manager->get_course(), array('customint1' => $jwcid, 'roleid' => $roleid));
        enrol_jwc_sync($manager->get_course()->id);
        break;
    case 'enroljwcusers':
        require_capability('enrol/manual:enrol', $context);
        $roleid = required_param('roleid', PARAM_INT);
        $jwcid = required_param('jwcid', PARAM_INT);
        $result = enrol_jwc_enrol_all_users($manager, $jwcid, $roleid);

        $roles = $manager->get_assignable_roles();
        $jwcs = enrol_jwc_get_jwcs($manager);
        if (!array_key_exists($jwcid, $jwcs) || !array_key_exists($roleid, $roles)) {
            throw new enrol_ajax_exception('errorenroljwc');
        }
        if ($result === false) {
            throw new enrol_ajax_exception('errorenroljwcusers');
        }
        $outcome->success = true;
        $outcome->response->users = $result;
        $outcome->response->title = get_string('success');
        $outcome->response->message = get_string('enrollednewusers', 'enrol', $result);
        $outcome->response->yesLabel = get_string('ok');
        break;
    default:
        throw new enrol_ajax_exception('unknowajaxaction');
}

echo json_encode($outcome);
die();