{{--

Java Edit Form Blade
NOTE: - java include section is in default blade at bottom of text
      - used to add java script stuff to form/edit blade

@param $form_fields: the form fields to be displayed as array()

--}}

@section ('java')

	<script>setTimeout("document.getElementById('success_msg').style.display='none';", 6000);</script>
	<script>setTimeout("document.getElementById('delete_msg').style.display='none';", 6000);</script>

	<script type='text/javascript'>
		/*
		 * jQuery Plugin for "on Hover" Mouse Events:
		 * use title option in html element,
		 * see help msg on compute_form_fields() in BaseViewController
		 */
		$(function() {
			$( document ).tooltip();
		});
	</script>

	<script type="text/javascript">

		{{-- jquery (java script) based realtime showing/hiding of fields --}}
		{{-- foreach form field --}}
		@foreach($form_fields as $field)

			{{-- that has a select field with an array() inside --}}
			@if ((isset($field['select']) && is_array($field['select'])))

				{{-- load on document initialization --}}
				@include('Generic.form-js-select')

				{{-- the element change function --}}
				$('#{{$field['name']}}').change(function() {
					@include('Generic.form-js-select')
				});

			@endif

			{{-- current element is a checkbox --}}
			@if ($field['form_type'] == 'checkbox')

				@if (!isset($form_js_checkbox_blade_included))
					@include('Generic.form-js-checkbox')
					<?php $form_js_checkbox_blade_included = True; ?>
				@endif

				{{-- call onLoad to initialize the websites visibility states --}}
				par__toggle_class_visibility_depending_on_checkbox('{{$field['name']}}');

				{{-- call on change of a checkbox --}}
				$('#{{$field['name']}}').change(function() {
					par__toggle_class_visibility_depending_on_checkbox('{{$field['name']}}');
				});

			@endif

		@endforeach

	</script>
@stop
