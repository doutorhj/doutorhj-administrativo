<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Especialidade extends Model
{	  
	 public $fillable   = ['cd_especialidade', 'ds_especialidade'];

    public function getActive(){
        return DB::select(" SELECT DISTINCT e.*
                              FROM especialidades e
                              JOIN consultas c ON (e.id = c.especialidade_id)
                              JOIN atendimentos at ON (c.id = at.consulta_id)
                              JOIN clinicas cl ON (at.clinica_id = cl.id)
                             WHERE at.profissional_id IS NOT NULL
                               AND at.cs_status = 'A'
                               AND cl.cs_status = 'A'");
    }
}