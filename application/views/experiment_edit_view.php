<?=link_tag('css/spectrum.css');?>
<script src="js/spectrum.js"></script>
<script type="text/javascript">
$(function() {
	$("#wbs_number").on('keyup', function(e)  {
        $(this).val($(this).val().toUpperCase());
	});
	
	$('#wbs_number').mask(
		'AA.000000.0',
		{'translation': 
			{
				A: {pattern: /[A-Za-z]/}
			},
		 'placeholder': "__.______._"
		}
	);
});
</script>

<?=heading(lang('experiments'), 2); ?>

<?=$this->session->flashdata('message'); ?>

<?=form_open_multipart($action, array('class' => 'pure-form pure-form-aligned')); ?>

<?=form_fieldset($page_title); ?>
<?=form_input_and_label('name', $name, 'required'); ?>
<?=form_input_and_label('type', $type, 'required'); ?>
<?=form_input_and_label('description', $description, 'required'); ?>
<?=form_input_and_label('duration', $duration, 'required class="positive-integer"'); ?>
<?=form_dropdown_and_label('location', location_options($locations), $location_id); ?>
<?=form_input_and_label('wbs_number', $wbs_number, 'required'); ?>
<?=form_colorpicker('experiment_color', $experiment_color, 'required');?>

<?=form_fieldset('Eisen aan deelnemers'); ?>
<?=form_single_checkbox_and_label('dyslexic', $dyslexic); ?>
<?=form_single_checkbox_and_label('multilingual', $multilingual); ?>
<?=form_input_and_label('agefrommonths', $agefrommonths, 'required class="positive-integer"'); ?>
<?=form_input_and_label('agefromdays', $agefromdays, 'required class="positive-integer"'); ?>
<?=form_input_and_label('agetomonths', $agetomonths, 'required class="positive-integer"'); ?>
<?=form_input_and_label('agetodays', $agetodays, 'required class="positive-integer"'); ?>

<?=form_fieldset('Bellers en leiders'); ?>
<?=form_multiselect_and_label('callers', $callers, isset($current_caller_ids) ? $current_caller_ids: array()); ?>
<?=form_multiselect_and_label('leaders', $leaders, isset($current_leader_ids) ? $current_leader_ids: array()); ?>

<?=form_fieldset(lang('relations')); ?>
<?=form_multiselect_and_label('prerequisite', $experiments, isset($current_prerequisite_ids) ? $current_prerequisite_ids: array()); ?>
<?=form_multiselect_and_label('excludes', $experiments, isset($current_exclude_ids) ? $current_exclude_ids : array()); ?>
<?=form_dropdown_and_label('combination', $experiments, isset($current_combination_ids) ? $current_combination_ids : array()); ?>

<?=form_fieldset(lang('attachments')); ?>
<div class="pure-control-group">
<?=form_label(lang('attachment'), 'attachment'); ?>
<?php if ($attachment) { 
    echo '<em>' . $attachment . '</em> ';
    echo anchor(array('experiment/download_attachment', $id, 'attachment'), lang('download'));
    echo ' ';
    echo anchor(array('experiment/remove_attachment', $id, 'attachment'), lang('remove'), warning(lang('sure_remove_attachment')));
} else { ?>
    <input type="file" name="attachment" size="20" class="pure-input-rounded" />
    <?=form_error('attachment'); ?>
<?php } ?>
</div>
<div class="pure-control-group">
<?=form_label(lang('informedconsent'), 'informedconsent'); ?>
<?php if ($informedconsent) { 
    echo '<em>' . $informedconsent . '</em> ';
    echo anchor(array('experiment/download_attachment', $id, 'informedconsent'), lang('download'));
    echo ' ';
    echo anchor(array('experiment/remove_attachment', $id, 'informedconsent'), lang('remove'), warning(lang('sure_remove_informedconsent')));
} else { ?>
    <input type="file" name="informedconsent" size="20" class="pure-input-rounded" />
    <?=form_error('informedconsent'); ?>
<?php } ?>
</div>

<?=form_controls(); ?>
<?=form_fieldset_close(); ?>
<?=form_close(); ?>
