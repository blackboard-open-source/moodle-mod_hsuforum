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
 * This file contains a custom renderer class used by the forum module.
 *
 * @package   mod_hsuforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright Copyright (c) 2012 Open LMS (https://www.openlms.net)
 * @author Mark Nielsen
 */

use mod_hsuforum\local;
use mod_hsuforum\renderables\advanced_editor;

require_once(__DIR__.'/lib/discussion/subscribe.php');
require_once($CFG->dirroot.'/lib/formslib.php');
require_once($CFG->dirroot . '/grade/grading/lib.php');


/**
 * A custom renderer class that extends the plugin_renderer_base and
 * is used by the forum module.
 *
 * @package   mod_hsuforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright Copyright (c) 2012 Open LMS (https://www.openlms.net)
 * @author Mark Nielsen
 **/
class mod_hsuforum_renderer extends plugin_renderer_base {

    /**
     * @param $course
     * @param $cm
     * @param $forum
     * @param context_module $context
     * @author Mark Nielsen
     */
    public function view($course, $cm, $forum, context_module $context) {
        global $USER, $DB, $OUTPUT;

        require_once(__DIR__.'/lib/discussion/sort.php');

        $config = get_config('hsuforum');
        $mode    = optional_param('mode', 0, PARAM_INT); // Display mode (for single forum)
        $page    = optional_param('page', 0, PARAM_INT); // which page to show
        $forumicon = "<img src='".$OUTPUT->image_url('icon', 'hsuforum')."' alt='' class='iconlarge activityicon'/> ";
        echo '<div id="hsuforum-header"><h2>'.$forumicon.format_string($forum->name).'</h2>';
        if (!empty($forum->intro)) {
            echo '<div class="hsuforum_introduction">'.format_module_intro('hsuforum', $forum, $cm->id).'</div>';
        }
        echo "</div>";

        // Update activity group mode changes here.
        groups_get_activity_group($cm, true);

        $dsort = hsuforum_lib_discussion_sort::get_from_session($forum, $context);
        $dsort->set_key(optional_param('dsortkey', $dsort->get_key(), PARAM_ALPHA));
        hsuforum_lib_discussion_sort::set_to_session($dsort);


        if (!empty($forum->blockafter) && !empty($forum->blockperiod)) {
            $a = new stdClass();
            $a->blockafter = $forum->blockafter;
            $a->blockperiod = get_string('secondstotime'.$forum->blockperiod);
            echo $OUTPUT->notification(get_string('thisforumisthrottled', 'hsuforum', $a));
        }

        if ($forum->type == 'qanda' && !has_capability('moodle/course:manageactivities', $context)) {
            echo $OUTPUT->notification(get_string('qandanotify','hsuforum'));
        }

        switch ($forum->type) {
            case 'blog':
                hsuforum_print_latest_discussions($course, $forum, -1, 'd.pinned DESC, p.created DESC', -1, -1, $page, $config->manydiscussions, $cm);
                break;
            case 'eachuser':
                if (hsuforum_user_can_post_discussion($forum, null, -1, $cm)) {
                    echo '<p class="mdl-align">';
                    print_string("allowsdiscussions", "hsuforum");
                    echo '</p>';
                }
                // Fall through to following cases.
            default:
                hsuforum_print_latest_discussions($course, $forum, -1, $dsort->get_sort_sql(), -1, -1, $page, $config->manydiscussions, $cm);
                break;
        }
    }

    /**
     * Render all discussions view, including add discussion button, etc...
     *
     * @param stdClass $forum - forum row
     * @return string
     */
    public function render_discussionsview($forum) {
        global $CFG, $DB, $PAGE, $SESSION, $USER;

        ob_start(); // YAK! todo, fix this rubbish.

        require_once($CFG->dirroot.'/mod/hsuforum/lib.php');
        require_once($CFG->libdir.'/completionlib.php');
        require_once($CFG->libdir.'/accesslib.php');

        $output = '';

        $modinfo = get_fast_modinfo($forum->course);
        $forums = $modinfo->get_instances_of('hsuforum');
        if (!isset($forums[$forum->id])) {
            print_error('invalidcoursemodule');
        }
        $cm = $forums[$forum->id];

        $id          = $cm->id;      // Forum instance id (id in course modules table)
        $f           = $forum->id;        // Forum ID

        $config = get_config('hsuforum');

        if ($id) {
            if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
                print_error('coursemisconf');
            }
        } else if ($f) {
            if (! $course = $DB->get_record("course", array("id" => $forum->course))) {
                print_error('coursemisconf');
            }

            // move require_course_login here to use forced language for course
            // fix for MDL-6926
            require_course_login($course, true, $cm);
        } else {
            print_error('missingparameter');
        }

        $context = \context_module::instance($cm->id);

        if (!empty($CFG->enablerssfeeds) && !empty($config->enablerssfeeds) && $forum->rsstype && $forum->rssarticles) {
            require_once("$CFG->libdir/rsslib.php");

            $rsstitle = format_string($course->shortname, true, array('context' => \context_course::instance($course->id))) . ': ' . format_string($forum->name);
            rss_add_http_header($context, 'mod_hsuforum', $forum, $rsstitle);
        }

        // Mark viewed if required
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm);

        /// Some capability checks.
        if (empty($cm->visible) and !has_capability('moodle/course:viewhiddenactivities', $context)) {
            notice(get_string("activityiscurrentlyhidden"));
        }

        if (!has_capability('mod/hsuforum:viewdiscussion', $context)) {
            notice(get_string('noviewdiscussionspermission', 'hsuforum'));
        }

        // Mark viewed and trigger the course_module_viewed event.
        hsuforum_view($forum, $course, $cm, $context);

        if (!defined(AJAX_SCRIPT) || !AJAX_SCRIPT) {
            // Return here if we post or set subscription etc (but not if we are calling this via ajax).
            $SESSION->fromdiscussion = qualified_me();
        }

        $PAGE->requires->js_init_call('M.mod_hsuforum.init', null, false, $this->get_js_module());
        $output .= $this->svg_sprite();
        $this->view($course, $cm, $forum, $context);

        // Don't allow non logged in users, or guest to try to manage subscriptions.
        if (isloggedin() && !isguestuser()) {
            $forumobject = $DB->get_record("hsuforum", ["id" => $PAGE->cm->instance]);

            // Url's for different options in the discussion.
            $manageforumsubscriptionsurl = new \moodle_url('/mod/hsuforum/index.php', ['id' => $course->id]);
            $exporturl = new \moodle_url('/mod/hsuforum/route.php', ['contextid' => $context->id, 'action' => 'export']);
            $viewpostersurl = new \moodle_url('/mod/hsuforum/route.php', ['contextid' => $context->id, 'action' => 'viewposters']);
            $subscribeforumurl = new \moodle_url('/mod/hsuforum/subscribe.php', ['id' => $forum->id, 'sesskey' => sesskey()]);

            // Strings for the Url's.
            $manageforumsubscriptions = get_string('manageforumsubscriptions', 'mod_hsuforum');
            $exportdiscussions = get_string('export', 'mod_hsuforum');
            $viewposters = get_string('viewposters', 'mod_hsuforum');

            if (!hsuforum_is_subscribed($USER->id, $forumobject)) {
                $subscribe = get_string('subscribe', 'hsuforum');
            } else {
                $subscribe = get_string('unsubscribe', 'hsuforum');
            }

            // We need to verify that these outputs only appears for Snap, Boost will only display the manage forum subscriptions link.
            if (get_config('core', 'theme') == 'snap') {
                // Outputs for the Url's inside divs to have a correct position inside the page.
                $output .= '<div class="text-right"><hr>';
                $output .= '<div class="managesubscriptions-url">';
                $output .= \html_writer::link($manageforumsubscriptionsurl, $manageforumsubscriptions, ['class' => 'btn btn-link']);
                $output .= '</div>';
                $output .= '<div class="exportdiscussions-url">';
                $output .= \html_writer::link($exporturl, $exportdiscussions, ['class' => 'btn btn-link']);
                $output .= '</div>';
                $output .= '<div class="viewposters-url">';
                $output .= \html_writer::link($viewpostersurl, $viewposters, ['class' => 'btn btn-link']);
                $output .= '</div>';
                $output .= '<div class="subscribeforum-url">';
                $output .= \html_writer::link($subscribeforumurl, $subscribe, ['class' => 'btn btn-link']);
                $output .= '</div>';
                $output .= '</div>';
            } else {
                $output .= '<div class="text-right"><hr>';
                $output .= '<div class="managesubscriptions-url">';
                $output .= \html_writer::link($manageforumsubscriptionsurl, $manageforumsubscriptions, ['class' => 'btn btn-link']);
                $output .= '</div>';
                $output .= '</div>';
            }
        }

        if (!empty($CFG->mod_hsuforum_grading_interface)) {
            $gradingmanager = get_grading_manager($context, 'mod_hsuforum', 'posts');
            $gradingcontrollerpreview = '';
            if ($gradingmethod = $gradingmanager->get_active_method()) {
                $controller = $gradingmanager->get_controller($gradingmethod);
                if ($controller->is_form_defined()) {
                    $gradingcontrollerpreview = $controller->render_preview($PAGE);
                    if ($gradingcontrollerpreview) {
                        $output .= '<div class="text-right">';
                        $output .= \html_writer::link('#hsuforum_gradingcriteria', get_string('gradingmethodpreview', 'hsuforum'),
                            ['class' => 'btn btn-link text-right', 'data-toggle' => 'collapse', 'role' => 'button', 'aria-expanded' => 'false',
                                'aria-controls' => 'hsuforum_gradingcriteria']);
                        $output .= '</div>';
                        $output .= '<div class="row">
                                    <div class="col">
                                    <div class="collapse multi-collapse" id="hsuforum_gradingcriteria">
                                      <div class="card card-body">
                                      '. $gradingcontrollerpreview .'
                                      </div>
                                    </div>
                                  </div>
                                </div>';
                    }
                }
            }
        }

        $output = ob_get_contents().$output;

        ob_end_clean();

        return ($output);
    }

    /**
     * Render a list of discussions
     *
     * @param \stdClass $cm The forum course module
     * @param array $discussions A list of discussion and discussion post pairs, EG: array(array($discussion, $post), ...)
     * @param array $options Display options and information, EG: total discussions, page number and discussions per page
     * @return string
     */
    public function discussions($cm, array $discussions, array $options) {

        $output = '<div class="hsuforum-new-discussion-target"></div>';
        foreach ($discussions as $discussionpost) {
            list($discussion, $post) = $discussionpost;
            $output .= $this->discussion($cm, $discussion, $post, false, array(), null, true);
        }


        // TODO - this is confusing code
        return $this->notification_area().
            $this->output->container('', 'hsuforum-add-discussion-target').
            html_writer::tag('section', $output, array('role' => 'region', 'aria-label' => get_string('discussions', 'hsuforum'), 'class' => 'hsuforum-threads-wrapper', 'tabindex' => '-1')).
            $this->article_assets($cm);

    }

    /**
     * Render a single, stand alone discussion
     *
     * This is very similar to discussion(), but allows for
     * wrapping a single discussion in extra renderings
     * when the discussion is the only thing being viewed
     * on the page.
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The discussion to render
     * @param \stdClass $post The discussion's post to render
     * @param \stdClass[] $posts The discussion posts
     * @param null|boolean $canreply If the user can reply or not (optional)
     * @return string
     */
    public function discussion_thread($cm, $discussion, $post, array $posts, $canreply = null) {
        $output  = $this->discussion($cm, $discussion, $post, true, $posts, $canreply);
        $output .= $this->article_assets($cm);

        return $output;
    }

    /**
     * Render a single discussion
     *
     * Optionally also render the discussion's posts
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The discussion to render
     * @param \stdClass $post The discussion's post to render
     * @param \stdClass[] $posts The discussion posts (optional)
     * @param null|boolean $canreply If the user can reply or not (optional)
     * @param null|boolean $hidethreadcontent for main view(optional)
     * @return string
     */
    public function discussion($cm, $discussion, $post, $fullthread, array $posts = array(), $canreply = null, $hidethreadcontent = null) {
        global $DB, $PAGE, $USER;

        $forum = hsuforum_get_cm_forum($cm);
        $postuser = hsuforum_extract_postuser($post, $forum, context_module::instance($cm->id));
        $postuser->user_picture->size = 100;

        $course = hsuforum_get_cm_course($cm);
        if (is_null($canreply)) {
            $canreply = hsuforum_user_can_post($forum, $discussion, null, $cm, $course, context_module::instance($cm->id));
        }
        // Meta properties, sometimes don't exist.
        if (!property_exists($discussion, 'replies')) {
            if (!empty($posts)) {
                $discussion->replies = count($posts) - 1;
            } else {
                $discussion->replies = 0;
            }
        } else if (empty($discussion->replies)) {
            $discussion->replies = 0;
        }
        if (!property_exists($discussion, 'unread') or empty($discussion->unread)) {
            $discussion->unread = '-';
        }
        $format = get_string('articledateformat', 'hsuforum');

        $groups = groups_get_all_groups($course->id, 0, $cm->groupingid);
        $group = '';
        if (groups_get_activity_groupmode($cm, $course) > 0 && isset($groups[$discussion->groupid])) {
            $group = $groups[$discussion->groupid];
            $group = format_string($group->name);
        }

        $data           = new stdClass;
        $data->context  = context_module::instance($cm->id);
        $data->id       = $discussion->id;
        $data->postid   = $post->id;
        $data->unread   = $discussion->unread;
        $data->fullname = $postuser->fullname;
        $data->subject  = $this->raw_post_subject($post);
        $data->message  = $this->post_message($post, $cm);
        $data->created  = userdate($post->created, $format);
        $data->modified = userdate($discussion->timemodified, $format);
        $data->pinned   = $discussion->pinned;
        $data->replies  = $discussion->replies;
        $data->replyavatars = array();
        if ($data->replies > 0) {
            // Get actual replies
            $fields = user_picture::fields('u');
            $sql = "SELECT $fields, hp.max
                    FROM {user} u
                    JOIN (
                        SELECT userid, max(modified) as max
                        FROM {hsuforum_posts}
                        WHERE privatereply = 0 AND discussion = ?
                        GROUP BY userid
                    ) hp ON hp.userid = u.id
                    ORDER BY hp.max DESC";
            $replyusers = $DB->get_records_sql($sql, array($discussion->id));
            if (!empty($replyusers) && !$forum->anonymous) {
                foreach ($replyusers as $replyuser) {
                    if ($replyuser->id === $postuser->id) {
                        continue; // Don't show the posters avatar in the reply section.
                    }
                    $replyuser->imagealt = fullname($replyuser);
                    $data->replyavatars[] = $this->output->user_picture($replyuser, array('link' => false, 'size' => 100));
                }
            }
        }
        $data->group      = $group;
        $data->imagesrc   = $postuser->user_picture->get_url($this->page)->out();
        $data->userurl    = $this->get_post_user_url($cm, $postuser);
        $data->viewurl    = new moodle_url('/mod/hsuforum/discuss.php', array('d' => $discussion->id));
        $data->tools      = implode(' ', $this->post_get_commands($post, $discussion, $cm, $canreply));
        $data->postflags  = implode(' ',$this->post_get_flags($post, $cm, $discussion->id));
        $data->subscribe  = '';
        $data->posts      = '';
        $data->fullthread = $fullthread;
        $data->revealed   = false;
        $data->rawcreated = $post->created;
        $data->rawmodified = $discussion->timemodified;

        if ($forum->anonymous
                && $postuser->id === $USER->id
                && $post->reveal) {
            $data->revealed         = true;
        }

        if ($fullthread && $canreply) {
            $data->replyform = html_writer::tag(
                'div', $this->simple_edit_post($cm, false, $post->id), array('class' => 'hsuforum-footer-reply')
            );
        } else {
            $data->replyform = '';
        }

        if ($fullthread) {
            $data->posts = $this->posts($cm, $discussion, $posts, $canreply);
        }

        $subscribe = new hsuforum_lib_discussion_subscribe($forum, context_module::instance($cm->id));
        $data->subscribe = $this->discussion_subscribe_link($cm, $discussion, $subscribe) ;

        $config = get_config('hsuforum');
        $timeddiscussion = !empty($config->enabletimedposts) && ($discussion->timestart || $discussion->timeend);
        $timedoutsidewindow = ($timeddiscussion && ($discussion->timestart > time() || ($discussion->timeend != 0 && $discussion->timeend < time())));

        $canviewhiddentimedposts = has_capability('mod/hsuforum:viewhiddentimedposts', context_module::instance($cm->id));
        $canalwaysseetimedpost = ($USER->id == $postuser->id) || $canviewhiddentimedposts;
        if ($timeddiscussion && $canalwaysseetimedpost) {
            $data->timed = $PAGE->get_renderer('mod_hsuforum')->timed_discussion_tooltip($discussion, empty($timedoutsidewindow));
        } else {
            $data->timed = '';
        }

        return $this->discussion_template($data, $forum->type, $hidethreadcontent);
    }

    public function article_assets($cm) {
        $context = context_module::instance($cm->id);
        $this->article_js($context);
        if (!isloggedin()) {
            return '';
        }
        $output = html_writer::tag(
            'script',
            $this->simple_edit_post($cm),
            array('type' => 'text/template', 'id' => 'hsuforum-reply-template')
        );
        $output .= html_writer::tag(
            'script',
            $this->simple_edit_discussion($cm),
            array('type' => 'text/template', 'id' => 'hsuforum-discussion-template')
        );
        return $output;
    }

    /**
     * Render a single post
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The post's discussion
     * @param \stdClass $post The post to render
     * @param bool $canreply
     * @param null|object $parent Optional, parent post
     * @param array $commands Override default post commands
     * @param int $depth Depth of the post
     * @return string
     */
    public function post($cm, $discussion, $post, $canreply = false, $parent = null, $commands = array(), $depth = 0, $search = '') {
        global $USER, $CFG, $DB;

        $forum = hsuforum_get_cm_forum($cm);
        if (!hsuforum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            // Return a message about why you cannot see the post
            return "<div class='hsuforum-post-content-hidden'>".get_string('forumbodyhidden','hsuforum')."</div>";
        }
        if ($commands === false){
            $commands = array();
        } else if (empty($commands)) {
            $commands = $this->post_get_commands($post, $discussion, $cm, $canreply, false);
        } else if (!is_array($commands)){
            throw new coding_exception('$commands must be false, empty or populated array');
        }
        $postuser = hsuforum_extract_postuser($post, $forum, context_module::instance($cm->id));
        $postuser->user_picture->size = 100;

        // $post->breadcrumb comes from search btw.
        $data                 = new stdClass;
        $data->id             = $post->id;
        $data->discussionid   = $discussion->id;
        $data->fullname       = $postuser->fullname;
        $data->subject        = property_exists($post, 'breadcrumb') ? $post->breadcrumb : $this->raw_post_subject($post);
        $data->message        = $this->post_message($post, $cm, $search);
        $data->created        = userdate($post->created, get_string('articledateformat', 'hsuforum'));
        $data->rawcreated     = $post->created;
        $data->privatereply   = $post->privatereply;
        $data->imagesrc       = $postuser->user_picture->get_url($this->page)->out();
        $data->userurl        = $this->get_post_user_url($cm, $postuser);
        $data->unread         = empty($post->postread) ? true : false;
        $data->permalink      = new moodle_url('/mod/hsuforum/discuss.php#p'.$post->id, array('d' => $discussion->id));
        $data->isreply        = false;
        $data->parentfullname = '';
        $data->parentuserurl  = '';
        $data->tools          = implode(' ', $commands);
        $data->postflags      = implode(' ',$this->post_get_flags($post, $cm, $discussion->id, false));
        $data->depth          = $depth;
        $data->revealed       = false;

        if ($forum->anonymous
                && $postuser->id === $USER->id
                && $post->reveal) {
            $data->revealed         = true;
        }

        if (!empty($post->children)) {
            $post->replycount = count($post->children);
        }
        $data->replycount = '';
        // Only show reply count if replies and not first post
        if(!empty($post->replycount) && $post->replycount > 0 && $post->parent) {
            $data->replycount = hsuforum_xreplies($post->replycount);
        }

        // Mark post as read.
        if ($data->unread) {
            hsuforum_mark_post_read($USER->id, $post, $forum->id);
        }

        if (!empty($parent)) {
            $parentuser = hsuforum_extract_postuser($parent, $forum, context_module::instance($cm->id));
            $data->parenturl = $CFG->wwwroot.'/mod/hsuforum/discuss.php?d='.$parent->discussion.'#p'.$parent->id;
            $data->parentfullname = $parentuser->fullname;
            if (!empty($parentuser->user_picture)) {
                $parentuser->user_picture->size = 100;
                $data->parentuserurl = $this->get_post_user_url($cm, $parentuser);
                $data->parentuserpic = $this->output->user_picture($parentuser,
                    array('link' => false, 'size' => 100, 'alttext' => false));
            }
        }

        if ($depth > 0) {
            // Top level responses don't count.
            $data->isreply = true;
        }

        return $this->post_template($data);
    }

    public function discussion_template($d, $forumtype, $hidethreadcontent = null) {
        global $PAGE;

        $replies = '';
        if(!empty($d->replies)) {
            $xreplies = hsuforum_xreplies($d->replies);
            $replies = "<span class='hsuforum-replycount'>$xreplies</span>";
        }
        if (!empty($d->userurl)) {
            $byuser = html_writer::link($d->userurl, $d->fullname);
        } else {
            $byuser = html_writer::tag('span', $d->fullname);
        }
        $unread = '';
        $unreadclass = '';
        $attrs = '';
        if ($d->unread != '-') {
            $new  = get_string('unread', 'hsuforum');
            $unread  = "<a class='hsuforum-unreadcount disable-router' href='$d->viewurl#unread'>$new</a>";
            $attrs   = 'data-isunread="true"';
            $unreadclass = 'hsuforum-post-unread';
        }

        $author = s(strip_tags($d->fullname));
        $group = '';
        if (!empty($d->group)) {
            $group = '<br>'.$d->group;
        }

        $latestpost = '';
        if (!empty($d->modified) && !empty($d->replies)) {
            $latestpost = '<small class="hsuforum-thread-replies-meta">'.get_string('lastposttimeago', 'hsuforum', hsuforum_relative_time($d->rawmodified)).'</small>';
        }

        $participants = '<div class="hsuforum-thread-participants">'.implode(' ',$d->replyavatars).'</div>';


        $datecreated = hsuforum_relative_time($d->rawcreated, array('class' => 'hsuforum-thread-pubdate'));

        $threadtitle = $d->subject;
        if ($d->pinned) {
            $pinnedstr = get_string('discussionpinned', 'hsuforum');
            $threadtitle = $this->pix_icon('i/pinned', $pinnedstr, 'mod_hsuforum') . ' ' . $threadtitle;
        }
        if (!$d->fullthread) {
            $threadtitle = "<a class='disable-router' href='$d->viewurl'>$threadtitle</a>";
        }
        $options = get_string('options', 'hsuforum');
        $threadmeta  =
            '<div class="hsuforum-thread-meta">'
                .$replies
                .$unread
                .$participants
                .$latestpost
                .'<div class="hsuforum-thread-flags">'."{$d->subscribe} $d->postflags $d->timed</div>"
            .'</div>';

        if ($d->fullthread) {
            $tools = '<div role="region" class="hsuforum-tools hsuforum-thread-tools" aria-label="'.$options.'">'.$d->tools.'</div>';
            $blogmeta = '';
            $blogreplies = '';
        } else {
            $blogreplies = hsuforum_xreplies($d->replies);
            $tools = "<a class='disable-router hsuforum-replycount-link' href='$d->viewurl'>$blogreplies</a>";
            $blogmeta = $threadmeta;
        }

        $revealed = "";
        if ($d->revealed) {
            $nonanonymous = get_string('nonanonymous', 'mod_hsuforum');
            $revealed = '<span class="label label-danger">'.$nonanonymous.'</span>';
        }

        $threadcontent = '';
        if (!$hidethreadcontent) {
            $threadcontent = '<div class="hsuforum-thread-content" tabindex="0">' . $d->message . '</div>';
        }


        $threadheader = <<<HTML
        <div class="hsuforum-thread-header">
            <div class="hsuforum-thread-title">
                <h4 id='thread-title-{$d->id}' role="heading" aria-level="4">
                    $threadtitle
                </h4>
                <small>$datecreated</small>
            </div>
            $threadmeta
        </div>
HTML;

        return <<<HTML
<article id="p{$d->postid}" class="hsuforum-thread hsuforum-post-target clearfix" role="article"
    data-discussionid="$d->id" data-postid="$d->postid" data-author="$author" data-isdiscussion="true" $attrs>
    <header id="h{$d->postid}" class="clearfix $unreadclass">
        <div class="hsuforum-thread-author">
            <img class="userpicture img-circle" src="{$d->imagesrc}" alt="" />
            <p class="hsuforum-thread-byline">
                $byuser $group $revealed
            </p>
        </div>

        $threadheader
        $threadcontent
        $tools
    </header>

    <div id="hsuforum-thread-{$d->id}" class="hsuforum-thread-body">
        <!-- specific to blog style -->
        $blogmeta
        $d->posts
        $d->replyform
    </div>
</article>
HTML;
    }

    /**
     * Render a list of posts
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The discussion for the posts
     * @param \stdClass[] $posts The posts to render
     * @param bool $canreply
     * @throws coding_exception
     * @return string
     */
    public function posts($cm, $discussion, $posts, $canreply = false) {
        global $USER;

        $items = '';
        $count = 0;
        if (!empty($posts)) {
            if (!array_key_exists($discussion->firstpost, $posts)) {
                throw new coding_exception('Missing discussion post');
            }
            $parent = $posts[$discussion->firstpost];
            $items .= $this->post_walker($cm, $discussion, $posts, $parent, $canreply, $count);

            // Mark post as read.
            if (empty($parent->postread)) {
                $forum = hsuforum_get_cm_forum($cm);
                hsuforum_mark_post_read($USER->id, $parent, $forum->id);
            }
        }
        $output  = "<h5 role='heading' aria-level='5'>".hsuforum_xreplies($count)."</h5>";
        if (!empty($count)) {
            $output .= "<ol class='hsuforum-thread-replies-list'>".$items."</ol>";
        }
        return "<div class='hsuforum-thread-replies'>".$output."</div>";
    }

    /**
     * Internal method to walk over a list of posts, rendering
     * each post and their children.
     *
     * @param object $cm
     * @param object $discussion
     * @param array $posts
     * @param object $parent
     * @param bool $canreply
     * @param int $count Keep track of the number of posts actually rendered
     * @param int $depth
     * @return string
     */
    protected function post_walker($cm, $discussion, $posts, $parent, $canreply, &$count, $depth = 0) {
        $output = '';
        foreach ($posts as $post) {
            if ($post->parent != $parent->id) {
                continue;
            }
            $html = $this->post($cm, $discussion, $post, $canreply, $parent, array(), $depth);
            if (!empty($html)) {
                $count++;
                $output .= "<li class='hsuforum-post depth$depth' data-depth='$depth' data-count='$count'>".$html."</li>";

                if (!empty($post->children)) {
                    $output .= $this->post_walker($cm, $discussion, $posts, $post, $canreply, $count, ($depth + 1));
                }
            }
        }
        return $output;
    }

    /**
     * Return html for individual post
     *
     * 3 use cases:
     *  1. Standard post
     *  2. Reply to user
     *  3. Private reply to user
     *
     * @param object $p
     * @return string
     */
    public function post_template($p) {
        global $PAGE;

        $byuser = $p->fullname;
        if (!empty($p->userurl)) {
            $byuser = html_writer::link($p->userurl, $p->fullname);
        }
        $byline = get_string('postbyx', 'hsuforum', $byuser);
        if ($p->isreply) {
            $parent = $p->parentfullname;
            if (!empty($p->parentuserurl)) {
                $parent = html_writer::link($p->parentuserurl, $p->parentfullname);
            }
            if (empty($p->parentuserpic)) {
                $byline = get_string('replybyx', 'hsuforum', $byuser);
            } else {
                $byline = get_string('postbyxinreplytox', 'hsuforum', array(
                        'parent' => $p->parentuserpic.$parent,
                        'author' => $byuser,
                        'parentpost' => "<a title='".get_string('parentofthispost', 'hsuforum')."' class='hsuforum-parent-post-link disable-router' href='$p->parenturl'><span class='accesshide'>".get_string('parentofthispost', 'hsuforum')."</span>↑</a>"
                ));
            }
            if (!empty($p->privatereply)) {
                if (empty($p->parentuserpic)) {
                    $byline = get_string('privatereplybyx', 'hsuforum', $byuser);
                } else {
                    $byline = get_string('postbyxinprivatereplytox', 'hsuforum', array(
                            'author' => $byuser,
                            'parent' => $p->parentuserpic.$parent
                        ));
                }
            }
        } else if (!empty($p->privatereply)) {
            $byline = get_string('privatereplybyx', 'hsuforum', $byuser);
        }

        $author = s(strip_tags($p->fullname));
        $unread = '';
        $unreadclass = '';
        if ($p->unread) {
            $unread = "<span class='hsuforum-unreadcount'>".get_string('unread', 'hsuforum')."</span>";
            $unreadclass = "hsuforum-post-unread";
        }
        $options = get_string('options', 'hsuforum');
        $datecreated = hsuforum_relative_time($p->rawcreated, array('class' => 'hsuforum-post-pubdate'));


        $postreplies = '';
        if($p->replycount) {
            $postreplies = "<div class='post-reply-count accesshide'>$p->replycount</div>";
        }

        $newwindow = '';
        if ($PAGE->pagetype === 'local-joulegrader-view') {
            $newwindow = ' target="_blank"';
        }

        $revealed = "";
        if ($p->revealed) {
            $nonanonymous = get_string('nonanonymous', 'mod_hsuforum');
            $revealed = '<span class="label label-danger">'.$nonanonymous.'</span>';
        }

 return <<<HTML
<div class="hsuforum-post-wrapper hsuforum-post-target clearfix $unreadclass" id="p$p->id" data-postid="$p->id" data-discussionid="$p->discussionid" data-author="$author" data-ispost="true" tabindex="-1">

    <div class="hsuforum-post-figure">

        <img class="userpicture" src="{$p->imagesrc}" alt="">

    </div>
    <div class="hsuforum-post-body">
        <h6 role="heading" aria-level="6" class="hsuforum-post-byline" id="hsuforum-post-$p->id">
            $unread $byline $revealed
        </h6>
        <small class='hsuform-post-date'><a href="$p->permalink" class="disable-router"$newwindow>$datecreated</a></small>

        <div class="hsuforum-post-content">
            <div class="hsuforum-post-title">$p->subject</div>
                $p->message
        </div>
        <div role="region" class='hsuforum-tools' aria-label='$options'>
            <div class="hsuforum-postflagging">$p->postflags</div>
            $p->tools
        </div>
        $postreplies
    </div>
</div>
HTML;
    }

    /**
     * This method is used to generate HTML for a subscriber selection form that
     * uses two user_selector controls
     *
     * @param user_selector_base $existinguc
     * @param user_selector_base $potentialuc
     * @return string
     */
    public function subscriber_selection_form(user_selector_base $existinguc, user_selector_base $potentialuc) {
        $output = '';
        $formattributes = array();
        $formattributes['id'] = 'subscriberform';
        $formattributes['action'] = '';
        $formattributes['method'] = 'post';
        $output .= html_writer::start_tag('form', $formattributes);
        $output .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()));

        $existingcell = new html_table_cell();
        $existingcell->text = $existinguc->display(true);
        $existingcell->attributes['class'] = 'existing';
        $actioncell = new html_table_cell();
        $actioncell->text  = html_writer::start_tag('div', array());
        $actioncell->text .= html_writer::empty_tag('input', array('type'=>'submit', 'name'=>'subscribe', 'value'=>$this->page->theme->larrow.' '.get_string('add'), 'class'=>'actionbutton'));
        $actioncell->text .= html_writer::empty_tag('br', array());
        $actioncell->text .= html_writer::empty_tag('input', array('type'=>'submit', 'name'=>'unsubscribe', 'value'=>$this->page->theme->rarrow.' '.get_string('remove'), 'class'=>'actionbutton'));
        $actioncell->text .= html_writer::end_tag('div', array());
        $actioncell->attributes['class'] = 'actions';
        $potentialcell = new html_table_cell();
        $potentialcell->text = $potentialuc->display(true);
        $potentialcell->attributes['class'] = 'potential';

        $table = new html_table();
        $table->attributes['class'] = 'subscribertable boxaligncenter';
        $table->data = array(new html_table_row(array($existingcell, $actioncell, $potentialcell)));
        $output .= html_writer::table($table);

        $output .= html_writer::end_tag('form');
        return $output;
    }

    /**
     * This function generates HTML to display a subscriber overview, primarily used on
     * the subscribers page if editing was turned off
     *
     * @param array $users
     * @param string $entityname
     * @param object $forum
     * @param object $course
     * @return string
     */
    public function subscriber_overview($users, $entityname, $forum, $course) {
        $output = '';
        $modinfo = get_fast_modinfo($course);
        if (!$users || !is_array($users) || count($users)===0) {
            $output .= $this->output->heading(get_string("nosubscribers", "hsuforum"));
        } else if (!isset($modinfo->instances['hsuforum'][$forum->id])) {
            $output .= $this->output->heading(get_string("invalidmodule", "error"));
        } else {
            $cm = $modinfo->instances['hsuforum'][$forum->id];
            $canviewemail = in_array('email', get_extra_user_fields(context_module::instance($cm->id)));
            $strparams = new stdclass();
            $strparams->name = format_string($forum->name);
            $strparams->count = count($users);
            $output .= $this->output->heading(get_string("subscriberstowithcount", "hsuforum", $strparams));
            $table = new html_table();
            $table->cellpadding = 5;
            $table->cellspacing = 5;
            $table->tablealign = 'center';
            $table->data = array();
            foreach ($users as $user) {
                $info = array($this->output->user_picture($user, array('courseid'=>$course->id)), fullname($user));
                if ($canviewemail) {
                    array_push($info, $user->email);
                }
                $table->data[] = $info;
            }
            $output .= html_writer::table($table);
        }
        return $output;
    }

    /**
     * This is used to display a control containing all of the subscribed users so that
     * it can be searched
     *
     * @param user_selector_base $existingusers
     * @return string
     */
    public function subscribed_users(user_selector_base $existingusers) {
        $output  = $this->output->box_start('subscriberdiv boxaligncenter');
        $output .= html_writer::tag('p', get_string('forcessubscribe', 'hsuforum'));
        $output .= $existingusers->display(true);
        $output .= $this->output->box_end();
        return $output;
    }

    /**
     * Generate the HTML for an icon to be displayed beside the subject of a timed discussion.
     *
     * @param object $discussion
     * @param bool $visiblenow Indicicates that the discussion is currently
     * visible to all users.
     * @return string
     */
    public function timed_discussion_tooltip($discussion, $visiblenow) {
        $dates = array();
        if ($discussion->timestart) {
            $dates[] = get_string('displaystart', 'mod_hsuforum').': '.userdate($discussion->timestart);
        }
        if ($discussion->timeend) {
            $dates[] = get_string('displayend', 'mod_hsuforum').': '.userdate($discussion->timeend);
        }

        $str = $visiblenow ? 'timedvisible' : 'timedhidden';
        $dates[] = get_string($str, 'mod_hsuforum');

        $tooltip = implode("\n", $dates);
        return $this->pix_icon('i/calendar', $tooltip, 'moodle', array('class' => 'smallicon timedpost'));
    }

    /**
     * Display a forum post in the relevant context.
     *
     * @param \mod_hsuforum\output\hsuforum_post $post The post to display.
     * @return string
     */
    public function render_hsuforum_post_email(\mod_hsuforum\output\hsuforum_post_email $post) {
        $data = $post->export_for_template($this, $this->target === RENDERER_TARGET_TEXTEMAIL);
        return $this->render_from_template('mod_hsuforum/' . $this->hsuforum_post_template(), $data);
    }

    /**
     * The template name for this renderer.
     *
     * @return string
     */
    public function hsuforum_post_template() {
        return 'hsuforum_post';
    }

    /**
     * The javascript module used by the presentation layer
     *
     * @return array
     * @author Mark Nielsen
     */
    public function get_js_module() {
        return array(
            'name'      => 'mod_hsuforum',
            'fullpath'  => '/mod/hsuforum/module.js',
            'requires'  => array(
                'base',
                'node',
                'event',
                'anim',
                'panel',
                'dd-plugin',
                'io-base',
                'json',
                'core_rating',
            ),
            'strings' => array(
                array('jsondecodeerror', 'hsuforum'),
                array('ajaxrequesterror', 'hsuforum'),
                array('clicktoexpand', 'hsuforum'),
                array('clicktocollapse', 'hsuforum'),
                array('manualwarning', 'hsuforum'),
                array('subscribeshort', 'hsuforum'),
                array('unsubscribeshort', 'hsuforum'),
                array('toggle:bookmark', 'hsuforum'),
                array('toggle:subscribe', 'hsuforum'),
                array('toggle:substantive', 'hsuforum'),
                array('toggled:bookmark', 'hsuforum'),
                array('toggled:subscribe', 'hsuforum'),
                array('toggled:substantive', 'hsuforum'),
                array('addareply', 'hsuforum')

            )
        );
    }

    /**
     * Output substantive / bookmark toggles
     *
     * @param stdClass $post The post to add flags to
     * @param $cm
     * @param int $discussion id of parent discussion
     * @param bool $reply is this the first post in a thread or a reply
     * @throws coding_exception
     * @return array
     * @author Mark Nielsen
     */
    public function post_get_flags($post, $cm, $discussion, $reply = false) {
        global $PAGE, $CFG;

        $context = context_module::instance($cm->id);

        if (!local::cached_has_capability('mod/hsuforum:viewflags', $context)) {
            return array();
        }
        if (!property_exists($post, 'flags')) {
            throw new coding_exception('The post\'s flags property must be set');
        }
        require_once(__DIR__.'/lib/flag.php');

        $flaglib   = new hsuforum_lib_flag();
        $canedit   = local::cached_has_capability('mod/hsuforum:editanypost', $context);
        $returnurl = $this->return_url($cm->id, $discussion);

        $flaghtml = array();

        $forum = hsuforum_get_cm_forum($cm);
        foreach ($flaglib->get_flags() as $flag) {

            // Skip bookmark flagging if switched off at forum level or if global kill switch set.
            if ($flag == 'bookmark') {
                if ($forum->showbookmark === '0') {
                    continue;
                }
            }

            // Skip substantive flagging if switched off at forum level or if global kill switch set.
            if ($flag == 'substantive') {
                if ($forum->showsubstantive === '0') {
                    continue;
                }
            }

            $isflagged = $flaglib->is_flagged($post->flags, $flag);

            $url = new moodle_url('/mod/hsuforum/route.php', array(
                'contextid' => $context->id,
                'action'    => 'flag',
                'returnurl' => $returnurl,
                'postid'    => $post->id,
                'flag'      => $flag,
                'sesskey'   => sesskey()
            ));

            // Create appropriate area described by.
            $describedby = $reply ? 'thread-title-'.$discussion : 'hsuforum-post-'.$post->id;

            // Create toggle element.
            $flaghtml[$flag] = $this->toggle_element($flag,
                $describedby,
                $url,
                $isflagged,
                $canedit,
                array('class' => 'hsuforum_flag')
            );

        }

        return $flaghtml;
    }

    /**
     * Return Url for non-ajax fallback.
     *
     * When $PAGE is a route we need to create on based on the action
     * parameter.
     *
     * @param int $cmid
     * @param int $discussionid
     * @return string url
     */
    private function return_url($cmid, $discussionid) {
        global $PAGE;
        if (strpos($PAGE->url, 'route.php') === false) {
            return $PAGE->url;
        }
        $action = $PAGE->url->param('action');
        if ($action === 'add_discussion' ) {
            return "view.php?id=$cmid";
        } else if ($action === 'reply') {
            return "discuss.php?d=$discussionid";
        }
    }

    /**
     * Output a toggle_element.
     *
     * @param string $type - toggle type
     * @param string $label - label for toggle element
     * @param string $describedby - aria described by
     * @param moodle_url $url
     * @param bool $pressed
     * @param bool $link
     * @param null $attributes
     * @return string
     */
    public function toggle_element($type, $describedby, $url, $pressed = false, $link = true, $attributes = null) {
        if ($pressed) {
            $label = get_string('toggled:'.$type, 'hsuforum');
        } else {
            $label = get_string('toggle:'.$type, 'hsuforum');
        }
        if (empty($attributes)) {
            $attributes = array();
        }
        if (!isset($attributes['class'])){
            $attributes['class'] = '';
        }
        $classes = array($attributes['class'], 'hsuforum-toggle hsuforum-toggle-'.$type);
        if ($pressed) {
            $classes[] = 'hsuforum-toggled';
        }
        $classes = array_filter($classes);
        // Re-add classes to attributes.
        $attributes['class'] = implode(' ', $classes);
        $icon = '<svg viewBox="0 0 100 100" class="svg-icon '.$type.'">
        <title>'.$label.'</title>
        <use xlink:href="#'.$type.'"></use></svg>';
        if ($link) {
            $attributes['role']       = 'button';
            $attributes['data-toggletype'] = $type;
            $attributes['aria-pressed'] = $pressed ? 'true' :  'false';
            $attributes['aria-describedby'] = $describedby;
            $attributes['title']       = $type;
            return (html_writer::link($url, $icon, $attributes));
        } else {
            return (html_writer::tag('span', $icon, $attributes));
        }
    }

    /**
     * Adds a link to subscribe to a disussion
     *
     * @param stdClass $cm
     * @param stdClass $discussion
     * @param hsuforum_lib_discussion_subscribe $subscribe
     * @return string
     * @author Mark Nielsen / Guy Thomas
     */
    public function discussion_subscribe_link($cm, $discussion, hsuforum_lib_discussion_subscribe $subscribe) {

        if (!$subscribe->can_subscribe()) {
            return;
        }

        $returnurl = $this->return_url($cm->id, $discussion->id);

        $url = new moodle_url('/mod/hsuforum/route.php', array(
            'contextid'    => context_module::instance($cm->id)->id,
            'action'       => 'subscribedisc',
            'discussionid' => $discussion->id,
            'sesskey'      => sesskey(),
            'returnurl'    => $returnurl,
        ));

        $o = $this->toggle_element('subscribe',
            'thread-title-'.$discussion->id,
            $url,
            $subscribe->is_subscribed($discussion->id),
            true,
            array('class' => 'hsuforum_discussion_subscribe')
        );
        return $o;
    }

    /**
     * @param $cm
     * @param hsuforum_lib_discussion_sort $sort
     * @return string
     * @author Mark Nielsen
     */
    public function discussion_sorting($cm, hsuforum_lib_discussion_sort $sort) {

        $url = new moodle_url('/mod/hsuforum/view.php');

        $sortselect = html_writer::select($sort->get_key_options_menu(), 'dsortkey', $sort->get_key(), false, array('class' => ''));
        $sortform = "<form method='get' action='$url' class='hsuforum-discussion-sort'>
                    <legend class='accesshide'>".get_string('sortdiscussions', 'hsuforum')."</legend>
                    <input type='hidden' name='id' value='{$cm->id}'>
                    <label for='dsortkey' class='accesshide'>".get_string('orderdiscussionsby', 'hsuforum')."</label>
                    $sortselect
                    <input type='submit' class='btn btn-secondary' value='".get_string('sortdiscussionsby', 'hsuforum')."'>
                    </form>";

        return $sortform;
    }

    /**
     * @param stdClass $post
     * @return string
     */
    public function raw_post_subject($post) {
        if (empty($post->subjectnoformat)) {
            return format_string($post->subject);
        }
        return $post->subject;
    }

    /**
     * @param stdClass $post
     * @param stdClass $cm
     * @return string
     * @author Mark Nielsen
     */
    public function post_message($post, $cm, $search = '') {
        global $OUTPUT, $CFG;

        $options = new stdClass;
        $options->para    = false;
        $options->trusted = $post->messagetrust;
        $options->context = context_module::instance($cm->id);

        list($attachments, $attachedimages) = hsuforum_print_attachments($post, $cm, 'separateimages');

        $message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', context_module::instance($cm->id)->id, 'mod_hsuforum', 'post', $post->id);

        if (!empty($CFG->enableplagiarism)) {
            require_once($CFG->libdir.'/plagiarismlib.php');
            $message .= plagiarism_get_links(array('userid' => $post->userid,
                'content' => $message,
                'cmid' => $cm->id,
                'course' => $cm->course,
                'hsuforum' => $cm->instance));
        }
        
        $postcontent = format_text($message, $post->messageformat, $options, $cm->course);

        if (!empty($search)) {
            $postcontent = highlight($search, $postcontent);
        }

        if (!empty($attachments)) {
            $postcontent .= "<div class='attachments'>".$attachments."</div>";
        }
        if (!empty($attachedimages)) {
            $postcontent .= "<div class='attachedimages'>".$attachedimages."</div>";
        }

        if (\core_tag_tag::is_enabled('mod_hsuforum', 'hsuforum_posts')) {
             $postcontent .= $OUTPUT->tag_list(\core_tag_tag::get_item_tags('mod_hsuforum', 'hsuforum_posts', $post->id), null, 'forum-tags');
        }

        $forum = hsuforum_get_cm_forum($cm);
        if (!empty($forum->displaywordcount)) {
            $postcontent .= "<div class='post-word-count'>".get_string('numwords', 'moodle', hsuforum_word_count($post->message))."</div>";
        }
        $postcontent  = "<div class='posting'>".$postcontent."</div>";
        return $postcontent;
    }

    /**
     * @param stdClass $post
     * @return string
     * @author Mark Nielsen
     */
    public function post_rating($post) {
        $output = '';
        if (!empty($post->rating)) {
            $rendered = $this->render($post->rating);
            if (!empty($rendered)) {
                $output = "<div class='forum-post-rating'>".$rendered."</div>";
            }
        }
        return $output;
    }





    /**
     * @param $userid
     * @param $cm
     * @param null|stdClass $showonlypreference
     *
     * @return string
     * @author Mark Nielsen
     */
    public function user_posts_overview($userid, $cm, $showonlypreference = null) {
        global $PAGE;

        require_once(__DIR__.'/lib/flag.php');

        $config = get_config('hsuforum');

        $forum = hsuforum_get_cm_forum($cm);

        $showonlypreferencebutton = '';
        if (!empty($showonlypreference) and !empty($showonlypreference->button) and !$forum->anonymous) {
            $showonlypreferencebutton = $showonlypreference->button;
        }

        $output    = '';
        $postcount = $discussioncount = $flagcount = 0;
        $flaglib   = new hsuforum_lib_flag();
        if ($posts = hsuforum_get_user_posts($forum->id, $userid, context_module::instance($cm->id))) {
            $discussions = hsuforum_get_user_involved_discussions($forum->id, $userid);
            if (!empty($showonlypreference) and !empty($showonlypreference->preference)) {
                foreach ($discussions as $discussion) {

                    if ($discussion->userid == $userid and array_key_exists($discussion->firstpost, $posts)) {
                        $discussionpost = $posts[$discussion->firstpost];

                        $discussioncount++;
                        if ($flaglib->is_flagged($discussionpost->flags, 'substantive')) {
                            $flagcount++;
                        }
                    } else {
                        if (!$discussionpost = hsuforum_get_post_full($discussion->firstpost)) {
                            continue;
                        }
                    }
                    if (!$forum->anonymous) {
                        $output .= $this->post($cm, $discussion, $discussionpost, false, null, false);

                        $output .= html_writer::start_tag('div', array('class' => 'indent'));
                    }
                    foreach ($posts as $post) {
                        if ($post->discussion == $discussion->id and !empty($post->parent)) {
                            $postcount++;
                            if ($flaglib->is_flagged($post->flags, 'substantive')) {
                                $flagcount++;
                            }
                            $command = html_writer::link(
                                new moodle_url('/mod/hsuforum/discuss.php', array('d' => $discussion->id), 'p'.$post->id),
                                get_string('postincontext', 'hsuforum'),
                                array('target' => '_blank')
                            );
                            if (!$forum->anonymous) {
                                $output .= $this->post($cm, $discussion, $post, false, null, false);
                            }
                        }
                    }
                    if (!$forum->anonymous) {
                        $output .= html_writer::end_tag('div');
                    }
                }
            } else {
                foreach ($posts as $post) {
                    if (!empty($post->parent)) {
                        $postcount++;
                    } else {
                        $discussioncount++;
                    }
                    if ($flaglib->is_flagged($post->flags, 'substantive')) {
                        $flagcount++;
                    }
                    if (!$forum->anonymous) {
                        $command = html_writer::link(
                            new moodle_url('/mod/hsuforum/discuss.php', array('d' => $post->discussion), 'p'.$post->id),
                            get_string('postincontext', 'hsuforum'),
                            array('target' => '_blank')
                        );
                        $output .= $this->post($cm, $discussions[$post->discussion], $post, false, null, false);
                    }
                }
            }
        }
        if (!empty($postcount) or !empty($discussioncount)) {

            if ($forum->anonymous) {
                $output = html_writer::tag('h3', get_string('thisisanonymous', 'hsuforum'));
            }
            $counts = array(
                get_string('totalpostsanddiscussions', 'hsuforum', ($discussioncount+$postcount)),
                get_string('totaldiscussions', 'hsuforum', $discussioncount),
                get_string('totalreplies', 'hsuforum', $postcount)
            );

            if (!empty($config->showsubstantive)) {
                $counts[] = get_string('totalsubstantive', 'hsuforum', $flagcount);
            }

            if ($grade = hsuforum_get_user_formatted_rating_grade($forum, $userid)) {
                $counts[] = get_string('totalrating', 'hsuforum', $grade);
            }
            $countshtml = '';
            foreach ($counts as $count) {
                $countshtml .= html_writer::tag('div', $count, array('class' => 'hsuforum_count'));
            }
            $output = html_writer::div($countshtml, 'hsuforum_counts').$showonlypreferencebutton.$output;
            $output = html_writer::div($output, 'mod-hsuforum-posts-container article');
        }
        return $output;
    }

    /**
     * @param Exception[] $errors
     * @return string;
     */
    public function validation_errors($errors) {
        $message = '';
        if (count($errors) == 1) {
            $error = current($errors);
            $message = get_string('validationerrorx', 'hsuforum', $error->getMessage());
        } else if (count($errors) > 1) {
            $items = array();
            foreach ($errors as $error) {
                $items[] = $error->getMessage();
            }
            $message = get_string('validationerrorsx', 'hsuforum', array(
                'count'  => count($errors),
                'errors' => html_writer::alist($items, null, 'ol'),
            ));
        }
        return $message;
    }

    /**
     * Get the simple edit discussion form
     *
     * @param object $cm
     * @param int $postid
     * @param array $data Template data
     * @return string
     */
    public function simple_edit_discussion($cm, $postid = 0, array $data = array()) {
        global $DB, $USER, $OUTPUT;

        $context = context_module::instance($cm->id);
        $forum = hsuforum_get_cm_forum($cm);

        if (!empty($postid)) {
            $params = array('edit' => $postid);
            $legend = get_string('editingpost', 'hsuforum');
            $post = $DB->get_record('hsuforum_posts', ['id' => $postid]);
            $ownpost = true;
            if ($post->userid != $USER->id) {
                $ownpost = false;
                $user = $DB->get_record('user', ['id' => $post->userid]);
                $user = hsuforum_anonymize_user($user, $forum, $post);
                $data['userpicture'] = $this->output->user_picture($user, array('link' => false, 'size' => 100));
            }
        } else {
            $params  = array('forum' => $cm->instance);
            $ownpost = true;
            $post = new stdClass();
            $post->parent = false;
            $legend = get_string('addyourdiscussion', 'hsuforum');
            $thresholdwarning = hsuforum_check_throttling($forum, $cm);
            if (!empty($thresholdwarning)) {
                $message = get_string($thresholdwarning->errorcode, $thresholdwarning->module, $thresholdwarning->additional);
                $data['thresholdwarning'] = $OUTPUT->notification($message);
                if ($thresholdwarning->canpost === false) {
                    $data['thresholdblocked'] = " hsuforum-threshold-blocked ";
                }
            }
        }

        $data += array(
            'itemid'        => 0,
            'groupid'       => 0,
            'messageformat' => FORMAT_HTML,
        );
        $actionurl = new moodle_url('/mod/hsuforum/route.php', array(
            'action'        => (empty($postid)) ? 'add_discussion' : 'update_post',
            'sesskey'       => sesskey(),
            'edit'          => $postid,
            'contextid'     => $context->id,
            'itemid'        => $data['itemid'],
            'messageformat' => $data['messageformat'],
        ));

        $extrahtml = '';

        if ($forum->anonymous && $ownpost && has_capability('mod/hsuforum:revealpost', $context)) {
            $extrahtml .= html_writer::tag('label', get_string('reveal', 'hsuforum') . ' ' .
                    html_writer::checkbox('reveal', 1, !empty($post->reveal)));
        }

        if (groups_get_activity_groupmode($cm)) {
            $groupdata = groups_get_activity_allowed_groups($cm);

            $groupinfo = array();
            foreach ($groupdata as $groupid => $group) {
                // Check whether this user can post in this group.
                // We must make this check because all groups are returned for a visible grouped activity.
                if (hsuforum_user_can_post_discussion($forum, $groupid, null, $cm, $context)) {
                    // Build the data for the groupinfo select.
                    $groupinfo[$groupid] = $group->name;
                } else {
                    unset($groupdata[$groupid]);
                }
            }
            $groupcount = count($groupinfo);

            $canposttoowngroups = empty($postid)
                                    && $groupcount > 1
                                    && empty($post->parent)
                                    && has_capability('mod/hsuforum:canposttomygroups', $context);

            if ($canposttoowngroups) {
                $extrahtml .= html_writer::tag('label', get_string('posttomygroups', 'hsuforum') . ' ' .
                    html_writer::checkbox('posttomygroups', 1, false));
            }

            if (hsuforum_user_can_post_discussion($forum, -1, null, $cm, $context)) {
                // Note: We must reverse in this manner because array_unshift renumbers the array.
                $groupinfo = array_reverse($groupinfo, true );
                $groupinfo[-1] = get_string('allparticipants');
                $groupinfo = array_reverse($groupinfo, true );
                $groupcount++;
            }

            $canselectgroupfornew = empty($postid) && $groupcount > 1;

            $canselectgroupformove = $groupcount
                                        && !empty($postid)
                                        && has_capability('mod/hsuforum:movediscussions', $context);

            $canselectgroup = empty($post->parent) && ($canselectgroupfornew || $canselectgroupformove);

            if ($canselectgroup) {
                $groupselect = html_writer::tag('span', get_string('group') . ' ');
                $groupselect .= html_writer::select($groupinfo, 'groupinfo', $data['groupid'], false);
                $extrahtml .= html_writer::tag('label', $groupselect);
            } else {
                $actionurl->param('groupinfo', groups_get_activity_group($cm));
            }
        }

        $data += array(
            'postid'      => $postid,
            'context'     => $context,
            'forum'       => $forum,
            'actionurl'   => $actionurl,
            'class'       => 'hsuforum-discussion',
            'legend'      => $legend,
            'extrahtml'   => $extrahtml,
            'advancedurl' => new moodle_url('/mod/hsuforum/post.php', $params),
        );
        return $this->simple_edit_template($data);
    }

    /**
     * Get the simple edit post form
     *
     * @param object $cm
     * @param bool $isedit If we are editing or not
     * @param int $postid If editing, then the ID of the post we are editing. If
     *                    not editing, then the ID of the post we are replying to.
     * @param array $data Template data
     * @return string
     */
    public function simple_edit_post($cm, $isedit = false, $postid = 0, array $data = array()) {
        global $DB, $CFG, $USER, $OUTPUT;

        $context = context_module::instance($cm->id);
        $forum = hsuforum_get_cm_forum($cm);
        $postuser = $USER;
        $ownpost = false;

        if ($isedit) {
            $param  = 'edit';
            $legend = get_string('editingpost', 'hsuforum');
            $post = $DB->get_record('hsuforum_posts', ['id' => $postid]);
            if ($post->userid == $USER->id) {
                $ownpost = true;
            } else {
                $postuser = $DB->get_record('user', ['id' => $post->userid]);
                $postuser = hsuforum_anonymize_user($postuser, $forum, $post);
                $data['userpicture'] = $this->output->user_picture($postuser, array('link' => false, 'size' => 100));
            }
        } else {
            // It is a reply, AKA new post
            $ownpost = true;
            $param  = 'reply';
            $legend = get_string('addareply', 'hsuforum');
            $thresholdwarning = hsuforum_check_throttling($forum, $cm);
            if (!empty($thresholdwarning)) {
                $message = get_string($thresholdwarning->errorcode, $thresholdwarning->module, $thresholdwarning->additional);
                $data['thresholdwarning'] = $OUTPUT->notification($message);
                if ($thresholdwarning->canpost === false) {
                    $data['thresholdblocked'] = " hsuforum-threshold-blocked ";
                }
            }
        }

        $data += array(
            'itemid'        => 0,
            'privatereply'  => 0,
            'reveal'        => 0,
            'messageformat' => FORMAT_HTML,
        );
        $actionurl = new moodle_url('/mod/hsuforum/route.php', array(
            'action'        => ($isedit) ? 'update_post' : 'reply',
            $param          => $postid,
            'sesskey'       => sesskey(),
            'contextid'     => $context->id,
            'itemid'        => $data['itemid'],
            'messageformat' => $data['messageformat'],
        ));

        $extrahtml = '';
        if (has_capability('mod/hsuforum:allowprivate', $context, $postuser)
            && $forum->allowprivatereplies !== '0'
        ) {
            $extrahtml .= html_writer::tag('label', get_string('privatereply', 'hsuforum') . ' ' .
                    html_writer::checkbox('privatereply', 1, !empty($data['privatereply'])));
        }
        if ($forum->anonymous && $ownpost && has_capability('mod/hsuforum:revealpost', $context)) {
            $extrahtml .= html_writer::tag('label', get_string('reveal', 'hsuforum') . ' ' .
                    html_writer::checkbox('reveal', 1, !empty($data['reveal'])));
        }
        $data += array(
            'postid'          => ($isedit) ? $postid : 0,
            'context'         => $context,
            'forum'           => $forum,
            'actionurl'       => $actionurl,
            'class'           => 'hsuforum-reply',
            'legend'          => $legend,
            'extrahtml'       => $extrahtml,
            'subjectrequired' => $isedit,
            'advancedurl'     => new moodle_url('/mod/hsuforum/post.php', array($param => $postid)),
        );
        return $this->simple_edit_template($data);
    }

    /**
     * The simple edit template
     *
     * @param array $t The letter "t" is for template! Put template variables into here
     * @return string
     */
    protected function simple_edit_template($t) {
        global $USER;

        $required = get_string('required');
        $subjectlabeldefault = get_string('subject', 'hsuforum');
        if (!array_key_exists('subjectrequired', $t) || $t['subjectrequired'] === true) {
            $subjectlabeldefault .= " ($required)";
        }

        // Apply some sensible defaults.
        $t += array(
            'postid'             => 0,
            'hidden'             => '',
            'subject'            => '',
            'subjectlabel'       => $subjectlabeldefault,
            'subjectrequired'    => true,
            'subjectplaceholder' => get_string('subjectplaceholder', 'hsuforum'),
            'message'            => '',
            'messagelabel'       => get_string('message', 'hsuforum')." ($required)",
            'messageplaceholder' => get_string('messageplaceholder', 'hsuforum'),
            'attachmentlabel'    => get_string('attachment', 'hsuforum'),
            'submitlabel'        => get_string('submit', 'hsuforum'),
            'cancellabel'        => get_string('cancel'),
            'userpicture'        => $this->output->user_picture($USER, array('link' => false, 'size' => 100)),
            'extrahtml'          => '',
            'advancedlabel'      => get_string('useadvancededitor', 'hsuforum'),
            'thresholdwarning'   => '' ,
            'thresholdblocked'   => '' ,
        );


        $t            = (object) $t;
        $legend       = s($t->legend);
        $subject      = s($t->subject);
        $hidden       = html_writer::input_hidden_params($t->actionurl);
        $actionurl    = $t->actionurl->out_omit_querystring();
        $advancedurl  = s($t->advancedurl);
        $messagelabel = s($t->messagelabel);
        $files        = '';
        $attachments  = new \mod_hsuforum\attachments($t->forum, $t->context);
        $canattach    = $attachments->attachments_allowed();

        $subjectrequired = '';
        if ($t->subjectrequired) {
            $subjectrequired = 'required="required"';
        }
        if (!empty($t->postid) && $canattach) {
            foreach ($attachments->get_attachments($t->postid) as $file) {
                $checkbox = html_writer::checkbox('deleteattachment[]', $file->get_filename(), false).
                    get_string('deleteattachmentx', 'hsuforum', $file->get_filename());

                $files .= html_writer::tag('label', $checkbox);
            }
            $files = html_writer::tag('legend', get_string('deleteattachments', 'hsuforum'), array('class' => 'accesshide')).$files;
            $files = html_writer::tag('fieldset', $files);
        }
        if ($canattach) {
            $files .= <<<HTML
                <label class="editor-attachments">
                    <span class="accesshide">$t->attachmentlabel</span>
                    <input type="file" name="attachment[]" multiple="multiple" />
                </label>
HTML;
        }

        $mailnowcb = '';
        $coursecontext = $t->context->get_course_context(false);
        if (empty($t->postid) && has_capability('moodle/course:manageactivities', $coursecontext)) {
            $mailnowcb = '<label>' . get_string('mailnow', 'hsuforum') . ' ' .
                    '<input name="mailnow" type="checkbox" value="1" id="id_mailnow"></label>';
        }
        $timestamp = time();
        if ($t->postid === 0) {
            $postype = 'new';
        } else {
            $postype = 'edit';
        }
        return <<<HTML
<div class="hsuforum-reply-wrapper$t->thresholdblocked">
    <form method="post" role="form" aria-label="$t->legend" class="hsuforum-form $t->class" action="$actionurl" autocomplete="off">
        <fieldset>
            <legend>$t->legend</legend>
            $t->thresholdwarning
            <div class="hsuforum-validation-errors" role="alert"></div>
            <div class="hsuforum-post-figure">
                $t->userpicture
            </div>
            <div class="hsuforum-post-body">
            <input type="hidden" id="hsuforum-post-type" value="$postype">
                <label>
                    <span class="accesshide">$t->subjectlabel</span>
                    <input type="text" placeholder="$t->subjectplaceholder" name="subject" class="form-control" $subjectrequired spellcheck="true" value="$subject" maxlength="255" />
                </label>
                <div id="editor-info"></div>
                <textarea name="message" class="hidden"></textarea>
                <div id="editor-target-container-$timestamp" data-placeholder="$t->messageplaceholder" aria-label="$messagelabel" contenteditable="true" required="required" spellcheck="true" role="textbox" aria-multiline="true" class="hsuforum-textarea">$t->message</div>

                $files
                <div class="advancedoptions">
                    $t->extrahtml
                </div>
                $hidden

                    <button type="submit" class="btn btn-primary">$t->submitlabel</button>
                    <a href="#" class="hsuforum-cancel disable-router btn btn-link">$t->cancellabel</a>
                    <a href="$advancedurl" class="hsuforum-use-advanced disable-router btn btn-link">$t->advancedlabel</a>

            </div>
        </fieldset>
    </form>
</div>
HTML;

    }

    public function article_js($context = null) {
        if (!$context instanceof \context) {
            $contextid = $this->page->context->id;
        } else {
            $contextid = $context->id;
        }
        // For some reason, I need to require core_rating manually...
        $this->page->requires->js_module('core_rating');
        $this->page->requires->yui_module(
            'moodle-mod_hsuforum-article',
            'M.mod_hsuforum.init_article',
            array(array(
                'contextId' => $contextid,
            ))
        );
        $this->page->requires->strings_for_js(array(
            'replytox',
            'xdiscussions',
            'deletesure',
        ), 'mod_hsuforum');
        $this->page->requires->string_for_js('changesmadereallygoaway', 'moodle');
    }

    protected function get_post_user_url($cm, $postuser) {
        if (!$postuser->user_picture->link) {
            return null;
        }
        return new moodle_url('/user/view.php', ['id' => $postuser->id, 'course' => $cm->course]);
    }

    protected function notification_area() {
        return "<div class='hsuforum-notification'aria-hidden='true' aria-live='assertive'></div>";
    }

    /**
     * @param stdClass $post
     * @param stdClass $discussion
     * @param stdClass $cm
     * @param bool $canreply
     * @return array
     * @throws coding_exception
     * @author Mark Nielsen
     */
    public function post_get_commands($post, $discussion, $cm, $canreply) {
        global $CFG, $USER, $OUTPUT;

        $discussionlink = new moodle_url('/mod/hsuforum/discuss.php', array('d' => $post->discussion));
        $ownpost        = (isloggedin() and $post->userid == $USER->id);
        $commands       = array();

        if (!property_exists($post, 'privatereply')) {
            throw new coding_exception('Must set post\'s privatereply property!');
        }

        $forum = hsuforum_get_cm_forum($cm);

        if ($canreply and empty($post->privatereply)) {
            $postuser   = hsuforum_extract_postuser($post, $forum, context_module::instance($cm->id));
            $replytitle = get_string('replybuttontitle', 'hsuforum', strip_tags($postuser->fullname));
            $commands['reply'] = html_writer::link(
                new moodle_url('/mod/hsuforum/post.php', array('reply' => $post->id)),
                get_string('reply', 'hsuforum'),
                array(
                    'title' => $replytitle,
                    'class' => 'hsuforum-reply-link btn btn-default',
                )
            );
        }

        // Hack for allow to edit news posts those are not displayed yet until they are displayed
        $age = time() - $post->created;
        if (!$post->parent && $forum->type == 'news' && $discussion->timestart > time()) {
            $age = 0;
        }
        if (($ownpost && $age < $CFG->maxeditingtime) || local::cached_has_capability('mod/hsuforum:editanypost', context_module::instance($cm->id))) {
            $commands['edit'] = html_writer::link(
                new moodle_url('/mod/hsuforum/post.php', array('edit' => $post->id)),
                get_string('edit', 'hsuforum')
            );
        }

        if (($ownpost && $age < $CFG->maxeditingtime && local::cached_has_capability('mod/hsuforum:deleteownpost', context_module::instance($cm->id))) || local::cached_has_capability('mod/hsuforum:deleteanypost', context_module::instance($cm->id))) {
            $commands['delete'] = html_writer::link(
                new moodle_url('/mod/hsuforum/post.php', array('delete' => $post->id)),
                get_string('delete', 'hsuforum')
            );
        }

        if (local::cached_has_capability('mod/hsuforum:splitdiscussions', context_module::instance($cm->id))
                && $post->parent
                && !$post->privatereply
                && $forum->type != 'single') {
            $commands['split'] = html_writer::link(
                new moodle_url('/mod/hsuforum/post.php', array('prune' => $post->id)),
                get_string('prune', 'hsuforum'),
                array('title' => get_string('pruneheading', 'hsuforum'))
            );
        }


        if ($CFG->enableportfolios && empty($forum->anonymous) && (local::cached_has_capability('mod/hsuforum:exportpost', context_module::instance($cm->id)) || ($ownpost && local::cached_has_capability('mod/hsuforum:exportownpost', context_module::instance($cm->id))))) {
            require_once($CFG->libdir.'/portfoliolib.php');
            $button = new portfolio_add_button();
            $button->set_callback_options('hsuforum_portfolio_caller', array('postid' => $post->id), 'mod_hsuforum');
            list($attachments, $attachedimages) = hsuforum_print_attachments($post, $cm, 'separateimages');
            if (empty($attachments)) {
                $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
            } else {
                $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
            }
            $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
            if (!empty($porfoliohtml)) {
                $commands['portfolio'] = $porfoliohtml;
            }
        }

        $rating = $this->post_rating($post);
        if (!empty($rating)) {
            $commands['rating'] = $rating;
        }

        if (!$post->parent && has_capability('mod/hsuforum:pindiscussions', context_module::instance($cm->id))) {
            if ($discussion->pinned == HSUFORUM_DISCUSSION_PINNED) {
                $pinlink = HSUFORUM_DISCUSSION_UNPINNED;
                $pintext = get_string('discussionunpin', 'hsuforum');
            } else {
                $pinlink = HSUFORUM_DISCUSSION_PINNED;
                $pintext = get_string('discussionpin', 'hsuforum');
            }
            $pinurl = new moodle_url('/mod/hsuforum/discuss.php', [
                'pin' => $pinlink,
                'd' => $discussion->id,
                'sesskey' => sesskey(),
            ]);
            $commands['pin'] = $this->render_ax_button($pinurl, $pintext, 'post', $pinlink, $discussion->id);
        }

        return $commands;
    }

    /**
     * Render ax button for pin/unpin.
     * @param moodle_url $url
     * @param string $content
     * @param string $method
     * @param int $pinlink
     * @param int $discussion
     * @return string
     */
    public function render_ax_button(moodle_url $url, $content, $method = 'post', $pinlink, $discussion) {
        global $PAGE;

        $PAGE->requires->js_call_amd('mod_hsuforum/accessibility', 'init', array());
        $url = $method === 'get' ? $url->out_omit_querystring(true) : $url->out_omit_querystring();
        $output = html_writer::start_tag('div', ['class' => 'singlebutton']);
        $output .= html_writer::start_tag('form', ['method' => $method, 'action' => $url]);
        $output .= html_writer::tag('input', '',['type' => 'hidden', 'name' => 'pin', 'value' => $pinlink]);
        $output .= html_writer::tag('input', '',['type' => 'hidden', 'name' => 'd', 'value' => $discussion]);
        $output .= html_writer::tag('input', '',['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $output .= html_writer::start_tag('button', ['class' => 'pinbutton btn btn-default', 'aria-pressed' => 'false',
            'type' => 'submit', 'id' => html_writer::random_id('single_button')]);
        $output .= $content;
        $output .= html_writer::end_tag('button');
        $output .= html_writer::end_tag('form');
        $output .= html_writer::end_tag('div');
        return $output;
    }

    /**
     * Render dateform.
     * @param advanced_editor $advancededitor
     * @return string
     */
    public function render_advanced_editor(advanced_editor $advancededitor, $target, $targetid) {
        $data = $advancededitor->get_data($targetid);
        $editor = get_class($data->editor);
        if ($editor == 'atto_texteditor' || $editor == 'tinymce_texteditor'){
            if ($editor == 'tinymce_texteditor') {
                $data->options['enable_filemanagement'] = 1;
                $data->options['subdirs'] = 0;
                $data->options['maxfiles'] = -1;
                $data->options['changeformat'] = 0;
                $data->options['areamaxbytes'] = -1;
                $data->options['noclean'] = 0;
                $data->options['trusttext'] = 1;
                $data->options['return_types'] = 3;
                $data->options['enable_filemanagement'] = 1;
            }
            $data->editor->use_editor($target, $data->options, $data->fpoptions);
            if ($targetid != 0) {
                $draftitemid = $targetid;
            } else{
                $draftitemid = $data->draftitemid;
            }
            $draftitemidfld = '<input type="hidden" id="hiddenadvancededitordraftid" name="hiddenadvancededitor[itemid]" value="'.$draftitemid.'" />';
            return '<div id="hiddenadvancededitorcont">'.$draftitemidfld.'<textarea style="display:none" id="hiddenadvancededitor"></textarea></div>';
        }
        return '';
    }

    /**
     * Previous and next discussion navigation.
     *
     * @param stdClass|false $prevdiscussion
     * @param stdClass|false $nextdiscussion
     */
    public function discussion_navigation($prevdiscussion, $nextdiscussion) {
        global $PAGE;
        $output = '';
        $prevlink = '';
        $nextlink = '';

        if ($prevdiscussion) {
            $prevurl = new moodle_url($PAGE->URL);
            $prevurl->param('d', $prevdiscussion->id);
            $prevlink = '<div class="navigateprevious">'.
                '<div>'.get_string('previousdiscussion', 'hsuforum').'</div>'.
                '<div><a href="'.$prevurl->out().'">'.format_string($prevdiscussion->name).'</a></div>'.
                '</div>';
        }
        if ($nextdiscussion) {
            $nexturl = new moodle_url($PAGE->URL);
            $nexturl->param('d', $nextdiscussion->id);
            $nextlink = '<div class="navigatenext">'.
                '<div>'.get_string('nextdiscussion', 'hsuforum').'</div>'.
                '<div><a href="'.$nexturl->out().'">'.format_string($nextdiscussion->name).'</a></div>'.
                '</div>';
        }

        if ($prevlink !=='' || $nextlink !=='') {
            $output .= '<div class="discussnavigation">';
            $output .= $prevlink;
            $output .= $nextlink;
            $output .= '</div>';
        }
        return $output;
    }

    /**
     * SVG icon sprite
     *
     * @return string
     */
     //fill="#FFFFFF"
    public function svg_sprite() {
        return '<svg style="display:none" x="0px" y="0px"
             viewBox="0 0 100 100" enable-background="new 0 0 100 100">
        <g id="substantive">
            <polygon points="49.9,3.1 65,33.8 99,38.6 74.4,62.6 80.2,96.3 49.9,80.4 19.7,96.3 25.4,62.6
            0.9,38.6 34.8,33.8 "/>
        </g>
        <g id="bookmark">
            <polygon points="88.7,93.2 50.7,58.6 12.4,93.2 12.4,7.8 88.7,7.8 "/>
        </g>
        <g id="subscribe">
	       <polygon  enable-background="new    " points="96.7,84.3 3.5,84.3 3.5,14.8 50.1,49.6 96.7,14.8 	"/>
           <polygon  points="3.5,9.8 96.7,9.8 50.2,44.5 	"/>
        </g>
        </svg>';
    }

    /**
     * Create the inplace_editable used to select forum digest options.
     *
     * @param   stdClass    $forum  The forum to create the editable for.
     * @param   int         $value  The current value for this user
     * @return  inplace_editable
     */
    public function render_digest_options($forum, $value) {
        $options = hsuforum_get_user_digest_options();
        $editable = new \core\output\inplace_editable(
            'mod_hsuforum',
            'digestoptions',
            $forum->id,
            true,
            $options[$value],
            $value
        );

        $editable->set_type_select($options);

        return $editable;
    }

    /**
     * Render quick search form.
     *
     * @param \mod_hsuforum\output\quick_search_form $form The renderable.
     * @return string
     */
    public function render_quick_search_form(\mod_hsuforum\output\quick_search_form $form) {
        return $this->render_from_template('mod_hsuforum/quick_search_form', $form->export_for_template($this));
    }

    /**
     * Render big search form.
     *
     * @param \mod_hsuforum\output\big_search_form $form The renderable.
     * @return string
     */
    public function render_big_search_form(\mod_hsuforum\output\big_search_form $form) {
        return $this->render_from_template('mod_hsuforum/big_search_form', $form->export_for_template($this));
    }
}
