<?=heading($page_title, 2); ?>

<?=$this->session->flashdata('message'); ?>

<?php if ($participation->interrupted) { ?>
<p class="warning">
<?=lang('part_interrupted'); ?>
</p>
<?php } ?>

<!-- Participation info -->
<div>
	<table class="pure-table">
		<tr>
			<th><?=lang('participant'); ?></th>
			<td><?=is_leader() ? $participant->firstname : participant_get_link($participant); ?></td>
		</tr>
		<tr>
			<th><?=lang('parent'); ?></th>
			<td><?=is_leader() ? $participant->parentfirstname : parent_name($participant); ?></td>
		</tr>
		<tr>
			<th><?=lang('experiment'); ?></th>
			<td><?=experiment_get_link($experiment); ?></td>
		</tr>
		<?php if ($leader) { ?>
		<tr>
			<th><?=lang('leader'); ?></th>
			<td><?=user_get_link($leader); ?> 
				<?=' (' . anchor('participation/edit_leader/' . $participation->id, strtolower(lang('edit'))) . ')';?>
			</td>
		</tr>
		<?php } ?>
		<tr>
			<th><?=lang('risk_group'); ?></th>
			<td><?=$participation->risk ? lang('yes') : lang('no'); ?></td>
		</tr>
		<tr>
			<th><?=lang('last_called'); ?></th>
			<td><?=output_datetime($participation->lastcalled); ?></td>
		</tr>
		<tr>
			<th><?=lang('status'); ?></th>
			<td><?=lang($participation->status); ?></td>
		</tr>
		<tr>
			<th><?=lang('comment'); ?></th>
			<td><?=$participation->comment; ?></td>
		</tr>
		<?php if (!empty($participation->appointment)) { ?>
		<tr>
			<th><?=lang('appointment'); ?></th>
			<td><?=output_datetime($participation->appointment); ?></td>
		</tr>
		<tr>
			<th><?=lang('age'); ?></th>
			<td><?=age_in_months_and_days($participant->dateofbirth, $participation->appointment); ?>
			</td>
		</tr>
		<?php } ?>
		<?php if (!empty($participation->completed)) { ?>
		<tr>
			<th><?=lang('part_number'); ?></th>
			<td><?=$participation->part_number; ?></td>
		</tr>
		<?php } ?>
	</table>
	
</div>

<!-- Calls -->
<?php if (!is_leader()) { ?>
	<?=heading(lang('calls'), 3); ?>
	<div>
		<?php
			create_call_table('calls');
			$calls['id'] = 'calls';
			$calls['sort_column'] = 5;
			$calls['sort_order'] = 'desc';
			$calls['ajax_source'] = 'call/table_by_participation/' . $participation->id;
			echo $this->load->view('templates/list_view', $calls);
		?>
	</div>
<?php } ?>

<!-- Results -->
<?=heading(lang('results'), 3); ?>
<div>
	<?php
		create_result_table('results');
		$results['id'] = 'results';
		$results['ajax_source'] = 'result/table_by_participation/' . $participation->id;
		echo $this->load->view('templates/list_view', $results);
	?>
</div>
