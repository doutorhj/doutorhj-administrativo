@extends('layouts.master')

@section('title', 'Doutor HJ: Procedimentos')

@section('container')
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="page-title-box">
				<h4 class="page-title">Doutor HJ</h4>
				<ol class="breadcrumb float-right">
					<li class="breadcrumb-item"><a href="/">Home</a></li>
					<li class="breadcrumb-item"><a href="{{ route('procedimentos.index') }}">Lista de Cargos</a></li>
					<li class="breadcrumb-item active">Editar Cargo</li>
				</ol>
				<div class="clearfix"></div>
			</div>
		</div>
	</div>
	
	<div class="row">
		<div class="col-md-6 offset-md-3">
			<div class="card-box">
				<h4 class="header-title m-t-0">Editar Cargo</h4>
				
				<form action="{{ route('procedimentos.update', $procedimento->id) }}" method="post">
					<input type="hidden" name="_method" value="PUT">
					{!! csrf_field() !!}
					
					<div class="form-group">
						<label for="cd_procedimento">Código<span class="text-danger">*</span></label>
						<input type="text" id="cd_procedimento" class="form-control" name="cd_procedimento" value="{{ $procedimento->cd_procedimento }}" required placeholder="Código do Procedimento" maxlength="10"  >
					</div>
					
					<div class="form-group">
						<label for="ds_procedimento">Descrição<span class="text-danger">*</span></label>
						<input type="text" id="ds_procedimento" class="form-control" name="ds_procedimento" value="{{ $procedimento->ds_procedimento }}" required placeholder="Descrição do Procedimento" >
					</div>
					
					<div class="form-group">
						<label for="tipoatendimento_id">Tipo de Atendimento</label>
						<select id="tipoatendimento_id" class="form-control" name="tipoatendimento_id" placeholder="Selecione o Tipo de Atendimento">
						<option value="" >Selecione o Tipo de Especialidade</option>
						@foreach($tipo_atendimentos as $id => $titulo)
							<option value="{{ $id }}" @if( $id == $procedimento->tipoatendimento_id ) selected='selected' @endif >{{ $titulo }}</option>
						@endforeach
						</select>
					</div>
					
					<div class="form-group">
						<label for="grupoprocedimento_id">Grupo de Procedimento</label>
						<select id="grupoprocedimento_id" class="form-control" name="grupoprocedimento_id" placeholder="Selecione a Grupo de Procedimento">
						<option value="" >Selecione a Especialidade</option>
						@foreach($grupo_atendimentos as $id => $titulo)
							<option value="{{ $id }}" @if( $id == $procedimento->grupoprocedimento_id ) selected='selected' @endif >{{ $titulo }}</option>
						@endforeach
						</select>
					</div>
					
					<div class="form-group text-right m-b-0">
						<button type="submit" class="btn btn-primary waves-effect waves-light" ><i class="mdi mdi-content-save"></i> Salvar</button>
						<a href="{{ route('procedimentos.index') }}" class="btn btn-secondary waves-effect m-l-5"><i class="mdi mdi-cancel"></i> Cancelar</a>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
@endsection