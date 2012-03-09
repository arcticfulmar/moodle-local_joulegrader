<?php
/**
 * @author Sam Chaffee
 * @package local/joulegrader
 */

class local_joulegrader_form_comment extends moodleform {

    /**
     * Form definition
     */
    public function definition() {
        global $COURSE;

        $mform =& $this->_form;

        //tinymce params
        $tineymceparams = array(
            'plugins' => "safari,layer,emotions,inlinepopups,paste,directionality,save,iespell,preview,print,noneditable,visualchars,xhtmlxtras,template,spellchecker",
            'theme_advanced_buttons1' => "bold,italic,underline,strikethrough,bullist,numlist,|,undo,redo,",
            'theme_advanced_buttons1_add' => null,
            'theme_advanced_buttons2' => null,
            'theme_advanced_buttons2_add' => null,
            'theme_advanced_buttons3' => null,
            'theme_advanced_buttons3_add' => null,
        );

        //editoroptions
        $editoroptions = array('maxfiles' => EDITOR_UNLIMITED_FILES, 'trusttext'=>true, 'tinymceparams' => $tineymceparams);

        //comment editor
        $mform->addElement('editor', 'comment', null, array('cols' => 10), $editoroptions);
        $mform->addRule('comment', get_string('commentrequired', 'local_joulegrader'), 'required', null, 'client');

        //file manager
//        $mform->addElement('filemanager', 'attachments', null, null
//                , array('subdirs' => 0, 'maxbytes' => $COURSE->maxbytes, 'maxfiles' => 50));

        //submit button
        $this->add_action_buttons(false, get_string('add', 'local_joulegrader'));
    }
}