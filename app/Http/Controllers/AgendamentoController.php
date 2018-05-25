<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class AgendamentoController extends Controller
{
    /**
     * Display a listing of the resource.
     * 
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $clinicas = \App\Clinica::all();
//         DB::enableQueryLog();
        
        $clinica_id = Request::get('clinica_id');
        $nm_paciente = Request::get('nm_paciente');
        $data = UtilController::getDataRangeTimePickerToCarbon(Request::get('data'));
        
        $agenda = \App\Itempedido::WhereHas('agendamento', function($query){
        			$query->where('dt_atendimento', '>=', $data['de'])->where('dt_atendimento', '<=', $data['ate']);
                                             
                                             $arCsStatus = array();
                                             if( !empty(Request::get('ckPreAgendada'))            ) $arCsStatus[] = \App\Agendamento::PRE_AGENDADO;          
                                             if( !empty(Request::get('ckConsultasAgendadas'))     ) $arCsStatus[] = \App\Agendamento::AGENDADO;
                                             if( !empty(Request::get('ckConsultasConfirmadas'))   ) $arCsStatus[] = \App\Agendamento::CONFIRMADO;
                                             if( !empty(Request::get('ckConsultasNaoConfirmadas'))) $arCsStatus[] = \App\Agendamento::NAO_CONFIRMADO;
                                             if( !empty(Request::get('ckConsultasCanceladas'))    ) $arCsStatus[] = \App\Agendamento::CANCELADO;
                                             if( !empty(Request::get('ckAusencias'))              ) $arCsStatus[] = \App\Agendamento::AUSENTE;
                                             if( !empty(Request::get('ckRetornoConsultas'))       ) $arCsStatus[] = \App\Agendamento::RETORNO;
                                             if( !empty(Request::get('ckConsultasFinalizadas'))   ) $arCsStatus[] = \App\Agendamento::FINALIZADO;
                                             if( count($arCsStatus) > 0) $query->whereIn('cs_status', $arCsStatus);
                                         })->WhereHas('agendamento.clinica', function ($query) use ($clinica_id) { $query->where(DB::raw('id'), '=', Request::get('clinica_id'));
                                         })->With([ 
                                         'agendamento.profissional', 
                                         'agendamento.profissional.especialidades', 
                                         'agendamento.paciente'])
                                         ->WhereHas(
                                         'agendamento.paciente.user', function ($query) use ($nm_paciente) {$query->where(DB::raw('to_str(name)'), 'like', '%'.UtilController::toStr($nm_paciente).'%');})
                                         ->sortable()
                                         ->paginate(20);
//         $query_temp = DB::getQueryLog();
//         dd($query_temp);
//         dd($agenda);
        Request::flash();
        
        return view('agenda.index', compact('agenda', 'clinicas'));
    }
    
    /**
     * Consulta para alimentar autocomplete
     * 
     * @param string $consulta
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLocalAtendimento($consulta){
        $arJson = array();
        $consultas = \App\Clinica::where(DB::raw('to_str(nm_razao_social)'), 
                                            'like', '%'.UtilController::toStr($consulta).'%')->get();
        $consultas->load('documentos');
        
        foreach ($consultas as $query)
        {
            $nrDocumento = null;
            foreach($query->documentos as $objDocumento){
                if( $objDocumento->tp_documento == 'CNPJ' ){
                    $nrDocumento = $objDocumento->te_documento;
                }
            }
            
            $teDocumento = (!empty($nrDocumento)) ? ' - CNPJ: ' . $nrDocumento : null;
            $arJson[] = [ 'id' => $query->id, 'value' => $query->nm_razao_social . $teDocumento];
        }
        
        return Response()->json($arJson);
    }
    
    /**
     * Consulta para alimentar autocomplete
     * 
     * @param string $consulta
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfissional($profissional){
        $arJson = array();
        $profissional = \App\Profissional::where(function($query){

                                                })->get();
        $profissional->load('documentos');
        
        foreach ($profissional as $query)
        {
            foreach($query->documentos as $objDocumento){
                if( $objDocumento->tp_documento == 'CRM' or 
                        $objDocumento->tp_documento == 'CRO' ){
                    
                    $estado = \App\Estado::findorfail((int)$objDocumento->estado_id);
                    $teDocumento = $objDocumento->te_documento.' '.$objDocumento->tp_documento.'/'.$estado->sg_estado;
                }
            }
            
            $arJson[] = [ 'id' => $query->id, 'value' => $query->nm_primario.' '.$query->nm_secundario. ' '. $teDocumento];
        }
        
        return Response()->json($arJson);
    }
    
    /**
     * Realiza o agendamento/remarcacao de consultas
     *
     * @param integer $idClinica
     * @param integer $idProfissional
     * @param integer $idPaciente
     * @param integer $dia
     * @param integer $mes
     * @param integer $ano
     * @param string  $hora
     */
    public function addAgendamento($teTicket, $idClinica, $idProfissional, $idPaciente, 
                                   $dia=null, $mes=null, $ano=null, $hora=null, $boRemarcacao='N'){
        
        $agendamento = \App\Agendamento::where('paciente_id', '=', $idPaciente)->where('te_ticket', '=', $teTicket);
        
        if(is_null($ano) and is_null($hora)){
            $arDados = array('bo_remarcacao'  => $boRemarcacao,
                             'clinica_id'     => $idClinica,
                             'te_ticket'      => $teTicket,
                             'profissional_id'=> $idProfissional,
                             'cs_status'      => \App\Agendamento::AGENDADO);
        }else{
            $arDados = array('dt_atendimento' => new Carbon($ano.'-'.$mes.'-'.$dia.' '.$hora),
                             'bo_remarcacao'  => $boRemarcacao, 
                             'clinica_id'     => $idClinica,
                             'te_ticket'      => $teTicket,
                             'profissional_id'=> $idProfissional,
                             'cs_status'      => \App\Agendamento::AGENDADO);
        }
        
        $agendamento->update($arDados);
    }
    
    /**
     * Realiza o cancelamento de uma consulta por ticket.
     * 
     * @param string $teTicket
     */
    public function addCancelamento($teTicket, $obsCancelamento=null){
        $agendamento = \App\Agendamento::where('te_ticket', '=', $teTicket);
        
        $arDados = array('cs_status'=>\App\Agendamento::CANCELADO,
                         'obs_cancelamento'=> $obsCancelamento);
        $agendamento->update($arDados);
    }
    
    /**
     * Consulta lista de horários livres em uma data.
     *
     * @param date $data
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHorariosLivres($data){
        $arJson = array();
        
        for( $hora = 6; $hora <=22; $hora++ ){
            $this->_verificaDisponibilidadeHorario($data, str_pad($hora, 2, 0, STR_PAD_LEFT).':00:00');
            $this->_verificaDisponibilidadeHorario($data, str_pad($hora, 2, 0, STR_PAD_LEFT).':30:00');
        }
        
        return Response()->json($this->arHorariosLivres);
    }
    
    /**
     * Consulta disponibilidade de horário
     *
     * @param integer $hora
     * @return boolean
     */
    private function _verificaDisponibilidadeHorario($data, $hora){
        $agenda = \App\Agendamento::where('dt_atendimento', new Carbon($data.' '.$hora));
        if( $agenda->count() == 0 ){
            $this->arHorariosLivres[] = ['hora'=>substr($hora, 0, 5)];
            return true;
        }else{
            return false;
        }
    }
    
    /**
     * Muda Status de um agendamento
     * 
     * @param unknown $teTicket
     */
    public function setStatus($teTicket, $cdStatus){
        $agendamento = \App\Agendamento::where('te_ticket', '=', $teTicket);
        
        $arDados = array('cs_status' => $cdStatus);
        $agendamento->update($arDados);
    }    
}
