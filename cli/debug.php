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

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
require_once($CFG->libdir.'/clilib.php');      // cli only functions
require_once("$CFG->dirroot/enrol/jwc/locallib.php");

$longoptions  = array('xkid'=>true);
$shortoptions = array('x'=>'xkid');
list($options, $unrecognized) = cli_get_params($longoptions, $shortoptions);

if (empty($unrecognized)) {
    $help =
"Debug enrol jwc

Example:
\$sudo -u www-data /usr/bin/php enrol/jwc/cli/debug.php COURSE_NUMBER
\$sudo -u www-data /usr/bin/php enrol/jwc/cli/debug.php -x xkid
";

    echo $help;
    die;
}

$jwc = new jwc_helper();
$jwc_enrol = enrol_get_plugin('jwc');
if ($options['xkid']) {
    foreach ($unrecognized as $xkid) {
        $students = $jwc->get_all_students($xkid, $return_msg);
        foreach ($students as $student) { // print_r can not enum xml object
            $userid = $DB->get_field('user', 'id', array('auth'=>'cas', 'username'=>$student->code, 'lastname'=>$student->name));
            mtrace("$student->code\t$student->name\t[$userid]");
        }
        mtrace('Total: '.count($students));
        mtrace($return_msg);
    }
} else {
    foreach ($unrecognized as $course_number) {
        $courses = $jwc->get_all_courses($course_number, $jwc_enrol->get_config('semester'), $return_msg);
        print_r($courses);
        mtrace($return_msg);
    }
}

