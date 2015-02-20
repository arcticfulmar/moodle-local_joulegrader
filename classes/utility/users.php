<?php
namespace local_joulegrader\utility;
defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');

/**
 * joule Grader users utility
 *
 * @author Sam Chaffee
 * @package local/joulegrader
 */

class users extends loopable_abstract {

    /**
     * @var gradingareas
     */
    protected $gareautility;

    /**
     * @var groups
     */
    protected $groupsutility;

    /**
     * @var int|null
     */
    protected $loggedinuser;

    /**
     * @var int The passed guser
     */
    protected $guserparam;

    /**
     * @var \context_course
     */
    protected $coursecontext;

    /**
     * @param array $users
     * @param \context $context
     * @param array $gradebookroles
     *
     * @return array
     */
    public static function limit_to_gradebook_roles($users, $context) {
        $gradebookusers = self::users_with_gradebook_roles($context->id);
        $users = array_filter($users, function($user) use ($gradebookusers) {
            if (!empty($gradebookusers[$user->id])) {
                return true;
            }
            return false;
        });

        return $users;
    }

    public static function users_with_gradebook_roles($contextid) {
        global $DB, $CFG;

        if (!$context = \context::instance_by_id($contextid, IGNORE_MISSING)) {
            return false;
        }
        $parents = $context->get_parent_context_ids(true);
        list($contextsql, $contextparams) = $DB->get_in_or_equal($parents, SQL_PARAMS_NAMED, 'r');
        list($gradebookrolessql, $gradebookrolesparams) = $DB->get_in_or_equal(explode(',', $CFG->gradebookroles), SQL_PARAMS_NAMED, 'grbr0');
        $params = array_merge($contextparams, $gradebookrolesparams);

        $sql = <<<SQL
            SELECT DISTINCT ra.userid
              FROM {role_assignments} ra
             WHERE ra.roleid $gradebookrolessql
               AND ra.contextid $contextsql
SQL;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * @param gradingareas $gareautility
     * @param \context_course $context
     * @param int $guserparam
     * @param groups $groupsutility
     * @param null|int $loggedinuser
     * @param mixed $gradebookroles
     */
    public function __construct(gradingareas $gareautility, \context_course $context, $guserparam,
            groups $groupsutility = null, $loggedinuser = null, $gradebookroles = null) {

        global $USER, $CFG;
        $this->coursecontext = $context;
        if (is_null($loggedinuser)) {
            $loggedinuser = $USER->id;
        }
        if (is_null($groupsutility)) {
            $groupsutility = new groups($context, $loggedinuser);
        }
        if (is_null($gradebookroles)) {
            $gradebookroles = explode(',', $CFG->gradebookroles);
        } else if (!empty($gradebookroles) and !is_array($gradebookroles)) {
            $gradebookroles = explode(',', $gradebookroles);
        }
        $this->groupsutility = $groupsutility;
        $this->loggedinuser  = $loggedinuser;
        $this->gareautility  = $gareautility;
        $this->guserparam    = $guserparam;
        $this->gradebookroles = $gradebookroles;

        if ($this->loggedinuser_can_grade()) {
            $groupsutility->load_items();
            $this->load_items();
        } else {
            $this->current = $loggedinuser;
        }

    }

    public function get_groupsutility() {
        return $this->groupsutility;
    }

    /**
     * @return array
     */
    public function get_items() {
        return $this->items;
    }

    /**
     * @param string $capability
     * @param int $currentgroup
     * @return array
     */
    public function get_enrolled_users($capability, $currentgroup = 0) {
        // Get the enrolled users with the required capability.
        $users = get_enrolled_users($this->coursecontext, $capability, $currentgroup,
            'u.id, '.get_all_user_name_fields(true, 'u'));

        $users = self::limit_to_gradebook_roles($users, $this->coursecontext);

        return $users;
    }

    /**
     * Get the users menu
     *
     *
     * @return array - users that can be graded for the current area
     */
    public function load_items() {
        $this->items = array();

        // Is there a current grading area set?
        $currentarea = $this->gareautility->get_current();

        $requiredcap    = 'local/joulegrader:view';
        $classname      = '';
        $gradingareamgr = null;
        $needsgrading   = $this->gareautility->get_needsgrading();

        if (!empty($currentarea)) {
            //need to load the class for the grading area
            //determine classname based on the grading area
            $gradingareamgr = get_grading_manager($currentarea);

            $component = $gradingareamgr->get_component();
            $area = $gradingareamgr->get_area();

            $classname = "\\local_joulegrader\\gradingarea\\{$component}_{$area}";

            $method = 'get_studentcapability';
            //check to be sure the class was loaded
            if (class_exists($classname) && is_callable("{$classname}::{$method}")) {
                //find the grading area's required capability for students to appear in menu
                $requiredcap = $classname::$method();
            }
        } else if (!empty($needsgrading)) {
            // There is no current area and needsgrading filter is selected.
            return array();
        }
        $users = $this->get_enrolled_users($requiredcap, $this->groupsutility->get_current());

        // allow the plugin to narrow down the users
        $includemethod = 'include_users';
        if (!empty($classname) && is_callable("{$classname}::{$includemethod}")) {
            // check with the grading area class to make sure to include the current user
            $users = $classname::$includemethod($users, $gradingareamgr, $needsgrading);
        }

        // make sure that the plugin gave us an array back
        if (!is_array($users)) {
            return array();
        }

        foreach ($users as $userid => $user) {
            $this->items[$userid] = fullname($user);
        }

        return $this->items;
    }

    /**
     * Get the current user id
     *
     * @return int - id of the current user
     */
    public function get_current() {
        //if property is null currently then try to set it
        if (is_null($this->current)) {
            //first check to see if there was a param passed
            $guser = $this->guserparam;

            //if no param passed take the first user in the course (in the menu)
            if (empty($guser) && !empty($this->items)) {
                reset($this->items);
                $guser = key($this->items);
            } else if (!array_key_exists($guser, $this->items) && !empty($this->items)) {
                reset($this->items);
                $guser = key($this->items);
            }

            //special case where needs grading has excluded all grading areas
            $needsgrading = $this->gareautility->get_needsgrading();
            if (empty($this->items) && !empty($needsgrading)) {
                $guser = null;
            }

            $this->current = $guser;
        }

        return $this->current;
    }

    /**
     * @param $currentuser - user id for the currentuser
     */
    public function set_currentuser($currentuser) {
        $this->current = $currentuser;
    }

    /**
     * @param int $nextuser
     */
    public function set_nextuser($nextuser) {
        $this->next = $nextuser;
    }


    /**
     * @param int $prevuser
     */
    public function set_prevuser($prevuser) {
        $this->previous = $prevuser;
    }

    /**
     *
     */
    protected function loggedinuser_can_grade() {
        $cangrade = has_capability('local/joulegrader:grade', $this->coursecontext, $this->loggedinuser);
        $currentarea = $this->gareautility->get_current();
        if (!empty($currentarea)) {
            //need to load the class for the grading area
            //determine classname based on the grading area
            $gradingareamgr = get_grading_manager($currentarea);

            $component = $gradingareamgr->get_component();
            $area = $gradingareamgr->get_area();

            $classname = "\\local_joulegrader\\gradingarea\\{$component}_{$area}";

            $method = 'loggedinuser_can_grade';
            //check to be sure the class was loaded
            if (class_exists($classname) && is_callable("{$classname}::{$method}")) {
                //find the grading area's required capability for students to appear in menu
                $cangrade = $classname::$method($gradingareamgr, $this->loggedinuser);
            }
        }

        return $cangrade;
    }
}
