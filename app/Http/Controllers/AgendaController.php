<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Carbon;

class AgendaController extends Controller
{
    /**
     * Display a listing of the resource.
     * 
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $clinicas = \App\Clinica::all();
        
        $agenda = \App\Itempedido::with([
                                         'agendamento' => function($query){
                                             if(Request::get('data')){
                                                 $data = UtilController::getDataRangeTimePickerToCarbon(Request::get('data'));
                                                 
                                                 $query->where('dt_atendimento', '>=', $data['de']);
                                                 $query->Where('dt_atendimento', '<=', $data['ate']);
                                             }
                                             
                                             $arCsStatus = array();
                                             if( !empty(Request::get('ckPreAgendada'))            ) $arCsStatus[] = \App\Agendamento::PRE_AGENDADO;          
                                             if( !empty(Request::get('ckConsultasAgendadas'))     ) $arCsStatus[] = \App\Agendamento::AGENDADO;
                                             if( !empty(Request::get('ckConsultasConfirmadas'))   ) $arCsStatus[] = \App\Agendamento::CONFIRMADO;
                                             if( !empty(Request::get('ckConsultasNaoConfirmadas'))) $arCsStatus[] = \App\Agendamento::NAO_CONFIRMADO;
                                             if( !empty(Request::get('ckConsultasCanceladas'))    ) $arCsStatus[] = \App\Agendamento::CANCELADO;
                                             if( !empty(Request::get('ckAusencias'))              ) $arCsStatus[] = \App\Agendamento::AUSENTE;
                                             if( count($arCsStatus) > 0) $query->whereIn('cs_status', $arCsStatus);
                                         },
                                         'agendamento.clinica' => function ($query){
                                             $clinica_id = Request::get('clinica_id');
                                             if(!empty($clinica_id)){
                                                 $query->where(DB::raw('id'), '=', Request::get('clinica_id'));
                                             }
                                         }, 
                                         'agendamento.profissional', 
                                         'agendamento.profissional.especialidade', 
                                         'agendamento.paciente',
                                         'agendamento.paciente.user'=> function ($query){
                                              $nm_paciente = Request::get('nm_paciente');
                                              if(!empty($nm_paciente)){
                                                  $query->where(DB::raw('to_str(name)'), 'like', '%'.UtilController::toStr($nm_paciente).'%');
                                              }
                                         }, 
                                ])->where(function($query){})
                                         ->sortable()
                                         ->paginate(20);
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
//                         dd(Request::all());
                # $query->where(DB::raw('to_str(CONCAT(nm_primario, nm_secundario))'),'like', '%'.UtilController::toStr($profissional).'%');
            
            
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
    public function addAgendamento($teTicket, $idClinica, $idProfissional, $idPaciente, $dia, $mes, $ano, $hora, $boRemarcacao='N'){
        $agendamento = \App\Agendamento::where('paciente_id', '=', $idPaciente)->where('te_ticket', '=', $teTicket);
        
        
        $arDados = array('dt_atendimento' => new Carbon($ano.'-'.$mes.'-'.$dia.' '.$hora),
                         'bo_remarcacao'  => $boRemarcacao, 
                         'clinica_id'     => $idClinica,
                         'profissional_id'=> $idProfissional,
                         'cs_status'      => \App\Agendamento::AGENDADO);
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
}