@extends ('Layout.split')

@section('content_top')

		{{ HTML::linkRoute('Cmts.index', 'CMTS') }}

@stop

@section('content_left')

	<h2>Create CMTS</h2>
	
	{{ Form::open(array('route' => array('Cmts.store', 0), 'method' => 'POST')) }}

		@include('cmtsgws.form', array ('cmts' => null))
	
	{{ Form::submit('Create') }}
	{{ Form::close() }}

@stop