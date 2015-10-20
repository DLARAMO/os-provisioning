@extends ('Layout.split')

@include ('Modem.header')

@section('content_left')

	<h2>Edit Modem</h2>	

	{{ Form::model($modem, array('route' => array('Modem.update', $modem->id), 'method' => 'put')) }}

		@include('Modem.form', $modem)
	
	{{ Form::submit('Save') }}
	{{ Form::close() }}
	
@stop

@section('content_right')

	@foreach ($out as $line)

		<table>
		<tr>
			<td> 
				{{$line}}
			</td>
		</tr>

		</table>
		
	@endforeach

{{-- 
	<h2>Endpoints</h2>

	{{ Form::open(array('route' => 'Endpoint.create', 'method' => 'GET')) }}
	{{ Form::hidden ('modem_id', $modem->id) }}
	{{ Form::hidden ('modem_hostname', $modem->hostname) }}
	{{ Form::submit('Create') }}
	{{ Form::close() }}

	{{ Form::open(array('route' => array('Endpoint.destroy', 0), 'method' => 'delete')) }}

		@foreach ($modem->endpoints as $endpoint)

				<table>
				<tr>
					<td> 
						{{ Form::checkbox('ids['.$endpoint->id.']') }}
						{{ HTML::linkRoute('Endpoint.edit', $endpoint->hostname, $endpoint->id) }}
					</td>
				</tr>
				</table>
			
		@endforeach

	{{ Form::submit('Delete') }}
	{{ Form::close() }}
--}}

@stop