<?php

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

