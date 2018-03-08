<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Kyslik\ColumnSortable\Sortable;

class Profissional extends Model
{
	use Sortable;
	public $fillable      = ['nm_primario', 'nm_secundario', 'cs_sexo', 'dt_nascimento', 'tp_profissional', 'cs_status'];
	public $sortable      = ['id', 'nm_primario', 'nm_secundario'];
	public $dates 	      = ['dt_nascimento'];
	
	public function cargo(){
	    return $this->hasOne(Cargo::class);
	}
	
	public function agendamentos(){
	    return $this->hasMany(Agendamento::class);
	}

	public function contatos(){
	    return $this->belongsToMany(Contato::class, 'contato_profissional', 'profissional_id', 'contato_id');
	}
	
	public function enderecos(){
	    return $this->belongsToMany(Endereco::class, 'endereco_profissional', 'profissional_id', 'endereco_id');
	}
	
	public function documentos(){
	    return $this->belongsToMany(Documento::class, 'documento_profissional', 'profissional_id', 'documento_id');
	}
	
	public function especialidade(){
	    return $this->belongsTo(Especialidade::class, 'profissional_especialidade_id');
	}
	
	public function user(){
	    return $this->belongsTo(User::class);
	}
	
/*
	public function setDtNascimentoAttribute($value)
	{
	    $date = new Carbon($value);
	    $date->format('Y-m-d H:i:s');
	    
	    $this->attributes['dt_nascimento'] = $date;
	}
	   
	public function getDtNascimentoAttribute()
	{
	    $date = new Carbon($this->attributes['dt_nascimento']);
	    return $date->format('d/m/Y');
	}
*/
}