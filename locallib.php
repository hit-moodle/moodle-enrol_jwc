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
     * 返回semester学期中编号为coursenumber的课程中teachers下辖所有学生
     *
     * 返回数组，成员是用户id
     * 如无学生，返回空数组
     * 如遇错误，返回false
     */
    public function get_students($coursenumber, array $teachers, $semester, &$return_msg) {
        global $DB;

        if (! $courses = $this->get_courses($coursenumber, $semester, $return_msg)) {
            return false;
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
            $return_msg = '没有可同步的课程';
            return false;
        }

        $course = reset($courses);
        $return_msg = $course->kcname.'-'.implode(',', $xkids);

        // 获取学生
        $students = array();
        foreach ($xkids as $xkid) {
            $params = array();
            $params['id'] = $xkid;
            $jwcstr = $this->access('http://xscj.hit.edu.cn/hitjwgl/lxw/getinfoC.asp', $params);

            if ($this->has_error($jwcstr, $return_msg)) {
                return false;
            }

            $info = new SimpleXMLElement($jwcstr);
            foreach ($info->stud->item as $item) {
                if ($userid = $DB->get_field('user', 'id', array('auth'=>'cas', 'username'=>$item->code, 'lastname'=>$item->name))) {
                    $students[] = $userid;
                }
            }
        }

        return $students;
    }

    /**
     * 得到编号为coursenumber的所有课程信息
     *
     * 如果$semester为空，表示访问当前学期
     * 返回数组，成员是课程对象
     * 如编号正确但无对应课程，返回空数组
     * 如遇错误，返回false
     */
    public function get_courses($coursenumber, $semester, &$error) {
        $params = array();
        $params['xq'] = $semester;
        $params['id'] = $coursenumber;
        $jwcstr = $this->access('http://xscj.hit.edu.cn/hitjwgl/lxw/getinfoD.asp', $params);

        if ($this->has_error($jwcstr, $error)) {
            return false;
        }

        $info = new SimpleXMLElement($jwcstr);
        $courses = array();
        foreach ($info->course->item as $item) {
            $courses[] = $item;
        }

        return $courses;
    }

    protected function access($url_base, $params) {
        global $CFG;

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

        require_once("$CFG->libdir/filelib.php");
        return download_file_content($url);
    }

    protected function has_error($jwcstr, &$error) {
        $result = false; // no error

        if ($jwcstr === false) {
            $error = '访问教务处网站出错';
            $result = true;
        } else {
            $info = new SimpleXMLElement($jwcstr);
            if ($info->retu->flag == 0) {
                $error = $info->retu->errorinfo;
                $result = true;
            }
        }

        return $result;
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

    $jwc = new jwc_helper();
    $jwc_enrol = enrol_get_plugin('jwc');

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
            // 课程必须有cas认证的教师
            $teachers = enrol_jwc_get_cas_teachers($instance->courseid);
            if (empty($teachers)) {
                $DB->set_field('enrol', 'customchar2', '此课程没有使用HITID的教师', array('id' => $instance->id));
                continue;
            }

            // 从教务处获取所有选修该课程的学生
            $return_msg = '';
            $students = $jwc->get_students($instance->customchar1, $teachers, $jwc_enrol->get_config('semester'), $return_msg);
            $DB->set_field('enrol', 'customchar2', $return_msg, array('id' => $instance->id));
            if (!$students) { // 出错
                continue; // skip this instance. 就算出错，也别清理选课，以免意外。管理员更改学期名时再清理所有选课
            }

            // 开始同步
            // 选课
            foreach ($students as $userid) {
                $jwc_enrol->enrol_user($instance, $userid, $instance->roleid);
            }

            // 取消教务处删除的选课
            if (!empty($students)) {
                $where = "enrolid = $instance->id AND userid NOT IN (" . implode(',', $students) . ')';
                $ues = $DB->get_records_select('user_enrolments', $where);
                foreach ($ues as $ue) {
                    $jwc_enrol->unenrol_user($instance, $ue->userid);
                }
            }
        }
    }
}

function enrol_jwc_get_cas_teachers($courseid) {
    global $DB;

    $jwc_enrol = enrol_get_plugin('jwc');
    $teacherroleid = $jwc_enrol->get_config('teacherroleid');
    $context = get_context_instance(CONTEXT_COURSE, $courseid);

    $where = 'auth = :auth AND id IN (SELECT userid FROM {role_assignments} WHERE roleid = :roleid AND contextid = :contextid )';
    return $DB->get_records_select('user', $where, array('auth' => 'cas', 'roleid' => $teacherroleid, 'contextid' => $context->id));
}
