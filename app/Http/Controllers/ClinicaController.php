<?php

namespace App\Http\Controllers;

use App\Plano;
use App\Preco;
use App\TipoPreco;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\PrestadoresRequest;
use App\Http\Requests\EditarPrestadoresRequest;
use Illuminate\Support\Facades\Route;
use App\Http\Requests\PrecificacaoConsultaRequest;
use App\Http\Requests\PrecificacaoProcedimentoRequest;
use Illuminate\Support\Facades\Request as CVXRequest;
use LaravelLegends\PtBrValidator\Validator as CVXValidador;
use Maatwebsite\Excel\Facades\Excel;
use App\Clinica;
use App\User;
use App\Cargo;
use App\Cidade;
use App\Profissional;
use App\Atendimento;
use App\Estado;
use App\Especialidade;
use App\Documento;
use App\Contato;
use App\Endereco;
use App\Procedimento;
use App\Responsavel;
use App\RegistroLog;
use Illuminate\Support\Facades\Auth;
use App\Consulta;
use App\Filial;
use App\AreaAtuacao;

class ClinicaController extends Controller
{
    /**
     * Instantiate a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        try {
            $action = Route::current();
            $action_name = $action->action['as'];
            
            $this->middleware("cvx:$action_name");
        } catch (\Exception $e) {}
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
//     	DB::enableQueryLog();
        $prestadores = Clinica::join('clinica_contato', function ($query) {$query->on('clinica_contato.clinica_id', '=', 'clinicas.id');})
        				->join('contatos', function ($query) {$query->on('clinica_contato.contato_id', '=', 'contatos.id');})
        				->join('responsavels', function ($query) {$query->on('clinicas.responsavel_id', '=', 'responsavels.id');})
        				->join('users', function ($query) {$query->on('responsavels.user_id', '=', 'users.id');});
        
        if(!empty(Request::input('nm_busca'))){
        	if(!empty(Request::input('tp_filtro')) && Request::input('tp_filtro') == 'nm_razao_social'){
        		$prestadores->where(DB::raw('to_str(nm_razao_social)'), 'like', '%'.UtilController::toStr(Request::input('nm_busca')).'%');
        	} elseif (!empty(Request::input('tp_filtro_nm_fantasia')) && Request::input('tp_filtro_nm_fantasia') == 'nm_fantasia') {
        		$prestadores->where(DB::raw('to_str(nm_fantasia)'), 'like', '%'.UtilController::toStr(Request::input('nm_busca')).'%');
        	} else {
        		$prestadores->where(DB::raw('to_str(nm_razao_social)'), 'like', '%'.UtilController::toStr(Request::input('nm_busca')).'%');
        	}
        }
        
        $uf = Request::input('sg_estado');
        $prestadores->join('clinica_endereco', function ($query) {$query->on('clinica_endereco.clinica_id', '=', 'clinicas.id');})
        				->join('enderecos', function ($query) {$query->on('clinica_endereco.endereco_id', '=', 'enderecos.id');})
        				->join('cidades', function ($query) use ($uf) { if(!empty(Request::input('sg_estado'))){ $query->on('enderecos.cidade_id', '=', 'cidades.id')->on('cidades.sg_estado', '=', DB::raw("'$uf'")); } else { $query->on('enderecos.cidade_id', '=', 'cidades.id'); }});
        
        if(!empty(Request::input('tp_filtro_pre_cadastro')) && Request::input('tp_filtro_pre_cadastro') == 'pre_cadastro'){
            $prestadores->where(['clinicas.cs_status' => 'I'])->where('pre_cadastro', true)->orderby('clinicas.id', 'desc');
        } else {
            $prestadores->where(DB::raw('clinicas.cs_status'), '=', 'A');
        }        
        
        $prestadores = $prestadores->select('clinicas.id AS id', 'clinicas.nm_razao_social', 'clinicas.nm_fantasia', 'clinicas.responsavel_id', 'users.name AS nome_responsavel', 'sg_estado', 'contatos.ds_contato')->sortable(['id' => 'desc'])->paginate(10);
//         $prestadores = $prestadores->sortable(['id' => 'desc'])->paginate(10);
//         dd($prestadores);
//         $prestadores->load('contatos');
//         $prestadores->load('responsavel');

        $prestadores->load('enderecos');
//          dd($prestadores);
//         dd( DB::getQueryLog() );
        
        $estados = Estado::orderBy('ds_estado')->select('estados.id', 'estados.sg_estado')->get();
        
        Request::flash();

        return view('clinicas.index', compact('prestadores', 'estados'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $estados = Estado::orderBy('ds_estado')->get();
        $cargos  = Cargo::orderBy('ds_cargo')->get(['id', 'ds_cargo']);

        $precoconsultas = null;
        $precoprocedimentos = null;

        $list_profissionals = [];

        return view('clinicas.create', compact('estados', 'cargos', 'precoprocedimentos', 'precoconsultas', 'list_profissionals'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(PrestadoresRequest $request)
    {
        ########### STARTING TRANSACTION ############
        DB::beginTransaction();
        #############################################

        try {
            # dados de acesso do usuário que é o profissional responsável pela empresa
            $usuario            = new User();
            $usuario->name      = $request->input('name_responsavel');
            $usuario->email     = $request->input('email');
            $usuario->password  = bcrypt($request->input('password'));
            $usuario->tp_user   = 'CLI';
            $usuario->cs_status = 'A';
            $usuario->avatar = 'users/default.png';
            $usuario->perfiluser_id = 2;
            $usuario->save();

            # documento da empresa CNPJ
            $documentoCnpj      = new Documento();
            $documentoCnpj->tp_documento = $request->input('tp_documento');
            $documentoCnpj->te_documento = UtilController::retiraMascara($request->input('te_documento'));
            $documentoCnpj->save();
            $documento_ids = [$documentoCnpj->id];

            # endereco da empresa
            $endereco           = new Endereco($request->all());
            $cidade             = Cidade::where(['cd_ibge'=>$request->input('cd_cidade_ibge')])->get()->first();
            $endereco->nr_cep = UtilController::retiraMascara($request->input('nr_cep'));
            $endereco->cidade()->associate($cidade);
            $endereco->nr_latitude_gps = $request->input('nr_latitude_gps');
            $endereco->nr_longitute_gps = $request->input('nr_longitute_gps');
            $endereco->save();
            $endereco_ids = [$endereco->id];

            # responsavel pela empresa
            $responsavel      = new Responsavel();
            $responsavel->telefone = $request->input('telefone_responsavel');
            $responsavel->cpf = UtilController::retiraMascara($request->input('cpf_responsavel'));
            $responsavel->user_id = $usuario->id;
            $responsavel->save();

            # telefones
            $arContatos = array();

            $contato1             = new Contato();
            $contato1->tp_contato = $request->input('tp_contato');
            $contato1->ds_contato = $request->input('ds_contato');
            $contato1->save();
            array_push($arContatos, $contato1->id);

            if(!empty($request->input('ds_contato2'))){
                $contato2             = new \App\Contato();
                $contato2->tp_contato = $request->input('tp_contato2');
                $contato2->ds_contato = $request->input('ds_contato2');
                $contato2->save();
                array_push($arContatos, $contato2->id);
            }

            if(!empty($request->input('ds_contato3'))){
                $contato3             = new \App\Contato();
                $contato3->tp_contato = $request->input('tp_contato3');
                $contato3->ds_contato = $request->input('ds_contato3');
                $contato3->save();
                array_push($arContatos, $contato3->id);
            }

            # clinica
            $clinica = Clinica::create($request->all());
            $clinica->responsavel_id = $responsavel->id;
            if ($clinica->save()) {

                # registra log
                $user_obj           = $usuario->toJson();
                $clinica_obj        = $clinica->toJson();
                $documento_obj      = $documentoCnpj->toJson();
                $endereco_obj       = $endereco->toJson();
                $responsavel_obj    = $responsavel->toJson();
                $contato_obj        = $contato1->toJson();

                //$log = "[$user_obj, $clinica_obj, $documento_obj, $endereco_obj, $responsavel_obj, $contato_obj]";
                
                $titulo_log = 'Adicionar Clinica';
                $ct_log   = '"reg_anterior":'.'{}';
                $new_log  = '"reg_novo":'.'{"user":'.$user_obj.', "clinica":'.$clinica_obj.', "documento":'.$documento_obj.', "endereco":'.$endereco_obj.', "responsavel":'.$responsavel_obj.', "contato":'.$contato_obj.'}';
                $tipo_log = 1;
                
                $log = "{".$ct_log.",".$new_log."}";
                
                $reglog = new RegistroLogController();
                $reglog->registrarLog($titulo_log, $log, $tipo_log);

            }

            $prestador = $this->setClinicaRelations($clinica, $documento_ids, $endereco_ids, $arContatos);
        } catch (\Exception $e) {
            ########### FINISHIING TRANSACTION ##########
            DB::rollback();
            #############################################
            //return response()->json(['status' => false, 'mensagem' => 'O Pedido não foi salvo, devido a uma falha inesperada. Por favor, tente novamente.']);
            return redirect()->route('clinicas.index')->with('error-alert', 'O prestador não foi cadastrado. Por favor, tente novamente.');
        }

        ########### FINISHIING TRANSACTION ##########
        DB::commit();
        #############################################

        return redirect()->route('clinicas.index')->with('success', 'O prestador foi cadastrado com sucesso!');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($idClinica)
    {
        $estados = Estado::orderBy('ds_estado')->get();
        $cargos  = Cargo::orderBy('ds_cargo')->get(['id', 'ds_cargo']);


        $prestador = Clinica::findorfail($idClinica);
        $prestador->load('enderecos');
        $prestador->load('contatos');
        $prestador->load('documentos');
        $prestador->load('profissionals');

        $list_filials = Filial::with('endereco')->where('clinica_id', $prestador->id)->where('cs_status', '=', 'A')->orderBy('eh_matriz','desc')->get();

        $list_profissionals = $prestador->profissionals;
        $list_especialidades = Especialidade::orderBy('ds_especialidade', 'asc')->get();

        $user   = User::findorfail($prestador->responsavel->user_id);
        $cidade = '-------';
        
        if(!is_null($prestador->enderecos->first()->cidade_id)) {
            $cidade = Cidade::findorfail($prestador->enderecos->first()->cidade_id);
        }
        
        $documentoprofissional = [];

        $precoprocedimentos = Atendimento::where(['clinica_id'=> $idClinica, 'consulta_id'=> null])->get();
        $precoprocedimentos->load('procedimento');

        $precoconsultas = Atendimento::where(['clinica_id'=> $idClinica, 'procedimento_id'=> null])->get();
        $precoconsultas->load('consulta');
        
        $list_area_atuacaos = AreaAtuacao::where('cs_status', '=', 'A')->orderBy('titulo', 'asc')->get();

        return view('clinicas.show', compact('estados', 'cargos', 'prestador', 'user', 'cargo', 'list_filials', 'list_profissionals', 'list_especialidades',
			'list_area_atuacaos', 'cidade', 'documentoprofissional', 'precoprocedimentos', 'precoconsultas'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($idClinica)
    {
        $estados = Estado::orderBy('ds_estado')->get();
        $cargos  = Cargo::orderBy('ds_cargo')->get(['id', 'ds_cargo']);

        $get_term = CVXRequest::get('search_term');
        $search_term = UtilController::toStr($get_term);
        
        $sort_proced = CVXRequest::get('sort_proced') != '' ? CVXRequest::get('sort_proced') : 'atendimentos.id';
        $direction_proced = CVXRequest::get('direction_proced') != '' ? CVXRequest::get('direction_proced') : 'desc';
        $limit = 10;
        $page_proced = CVXRequest::get('page_proced') != '' ? intval(CVXRequest::get('page_proced')-1)*10 : 0;
        $ct_page_proced = CVXRequest::get('page_proced') != '' ? intval(CVXRequest::get('page_proced')) : 1;
        
        $sort_consulta = CVXRequest::get('sort_consulta') != '' ? CVXRequest::get('sort_consulta') : 'atendimentos.id';
        $direction_consulta = CVXRequest::get('direction_consulta') != '' ? CVXRequest::get('direction_consulta') : 'desc';
        $page_consulta = CVXRequest::get('page_consulta') != '' ? intval(CVXRequest::get('page_consulta')-1)*10 : 0;
        $ct_page_consulta = CVXRequest::get('page_consulta') != '' ? intval(CVXRequest::get('page_consulta')) : 1;
        
        $prestador = Clinica::findorfail($idClinica);
        $prestador->load('enderecos');
        $prestador->load('contatos');
        $prestador->load('documentos');
        $prestador->load('filials');
        
        $list_filials = Filial::with(['endereco', 'documento', 'contato'])->where('clinica_id', $prestador->id)->where('cs_status', '=', 'A')->orderBy('eh_matriz','desc')->get();
        
        $documentosclinica = $prestador->documentos;

        $user   = User::findorfail($prestador->responsavel->user_id);
		$planos = Plano::pluck('ds_plano', 'id');

//		$precoprocedimentos = Atendimento::where('atendimentos.clinica_id', $idClinica)
//			->where('atendimentos.procedimento_id', '<>', null)
//			->where('atendimentos.cs_status', '=', 'A')
//			->select( DB::raw("atendimentos.procedimento_id, replace(ds_preco,'''','`') as ds_preco, atendimentos.clinica_id") )
//			->orderBy('atendimentos.procedimento_id')
////			->join('precos', 'precos.atendimento_id', '=', 'atendimentos.id')
//			->groupBy('atendimentos.id')
//			->with('precos')
//			->get();

		############# busca por procedimentos com paginacao ##################
		$nm_busca_proced = CVXRequest::get('nm_busca_proced');
		$search_term = UtilController::toStr($nm_busca_proced);
		
		
		$precoprocedimentos = Atendimento::with('procedimento')->where(['clinica_id' => $idClinica, 'cs_status' => 'A'])->whereNotNull('procedimento_id')
			->orderby($sort_proced, $direction_proced)
			->limit($limit)
			->offset($page_proced)
			->select('atendimentos.id', 'atendimentos.ds_preco', 'atendimentos.cs_status', 'atendimentos.clinica_id', 'atendimentos.consulta_id', 'atendimentos.procedimento_id', 'atendimentos.profissional_id');
		
		if(!empty($nm_busca_proced)) {
		    $precoprocedimentos->join('procedimentos', function($join) use ($search_term) { $join->on('atendimentos.procedimento_id', '=', 'procedimentos.id')->on(function($query) use ($search_term) { $query->where('cd_procedimento', 'LIKE', DB::raw("'%".$search_term."%'"))->orWhere(DB::raw('to_str(atendimentos.ds_preco)'), 'LIKE', '%'.$search_term.'%'); });})
		                       ->select('atendimentos.id', 'atendimentos.ds_preco', 'atendimentos.cs_status', 'atendimentos.clinica_id', 'atendimentos.consulta_id', 'atendimentos.procedimento_id', 'atendimentos.profissional_id', 'procedimentos.cd_procedimento', 'procedimentos.ds_procedimento', 'procedimentos.grupoprocedimento_id', 'procedimentos.tipoatendimento_id');
		}
		
		$precoprocedimentos = $precoprocedimentos->get();

		
		$total_procedimentos = Atendimento::where(['clinica_id' => $idClinica, 'cs_status' => 'A'])->where(DB::raw('to_str(ds_preco)'), 'LIKE', '%'.$search_term.'%')->whereNotNull('procedimento_id')->count();
		
		############# busca por consultas com paginacao ##################
		$nm_busca_consulta = CVXRequest::get('nm_busca_consulta');
		$search_term = UtilController::toStr($nm_busca_consulta);
// 		DB::enableQueryLog();
		$precoconsultas = Atendimento::where(['clinica_id' => $idClinica, 'cs_status' => 'A'])->whereNotNull('consulta_id')
			->orderby($sort_consulta, $direction_consulta)
			->limit($limit)
			->offset($page_consulta)
			->select('atendimentos.id', 'atendimentos.ds_preco', 'atendimentos.cs_status', 'atendimentos.clinica_id', 'atendimentos.consulta_id', 'atendimentos.procedimento_id', 'atendimentos.profissional_id');
		
		if(!empty($nm_busca_consulta)) {
		    $precoconsultas->join('consultas', function($join) use ($search_term) { $join->on('atendimentos.consulta_id', '=', 'consultas.id')->on(function($query) use ($search_term) { $query->where('cd_consulta', 'LIKE', DB::raw("'%".$search_term."%'"))->orWhere(DB::raw('to_str(atendimentos.ds_preco)'), 'LIKE', '%'.$search_term.'%'); });})
		                   ->select('atendimentos.id', 'atendimentos.ds_preco', 'atendimentos.cs_status', 'atendimentos.clinica_id', 'atendimentos.consulta_id', 'atendimentos.procedimento_id', 'atendimentos.profissional_id', 'consultas.cd_consulta', 'consultas.ds_consulta', 'consultas.especialidade_id', 'consultas.tipoatendimento_id');
		}
		
		$precoconsultas = $precoconsultas->get();
// 		$queries = DB::getQueryLog();
// 		dd($queries);
// 		dd($precoconsultas);
		$total_consultas = Atendimento::where(['clinica_id' => $idClinica, 'cs_status' => 'A'])->where(DB::raw('to_str(ds_preco)'), 'LIKE', '%'.$search_term.'%')->whereNotNull('consulta_id')->count();
		
        $documentoprofissional = [];

        if($search_term != '') {
            $list_profissionals = Profissional::where(DB::raw('to_str(nm_primario)'), 'LIKE', '%'.$search_term.'%')->where('clinica_id', $prestador->id)->where('cs_status', '=', 'A')->orderBy('nm_primario', 'asc')->get();
        } else {
            $list_profissionals = Profissional::where('clinica_id', $prestador->id)->where('cs_status', '=', 'A')->orderBy('nm_primario', 'asc')->get();
        }

        $list_profissionals->load('documentos');
        
        //--formata e trata situacoes onde o documento do profissional nao foi informado----------------------------------------------------
        for($i = 0; $i < sizeof($list_profissionals); $i++) {
        	
        	if(sizeof($list_profissionals[$i]->documentos) > 0) {
        		$list_profissionals[$i]->ds_documento = ' ('.$list_profissionals[$i]->documentos->first()->tp_documento.': '.$list_profissionals[$i]->documentos->first()->te_documento.')';
        	} else {
        		$list_profissionals[$i]->ds_documento = ' (DOCUMENTO NÃO INFORMADO)';
        	}
        }

        //$list_especialidades = Especialidade::orderBy('ds_especialidade', 'asc')->pluck('ds_especialidade', 'cd_especialidade', 'id');
        $list_especialidades = Especialidade::orderBy('ds_especialidade', 'asc')->get();
        
        $list_area_atuacaos = AreaAtuacao::where('cs_status', '=', 'A')->orderBy('titulo', 'asc')->get();
        
        return view('clinicas.edit', compact('estados', 'cargos', 'prestador', 'user', 'planos',
            'documentoprofissional', 'precoprocedimentos',
            'precoconsultas', 'documentosclinica', 'list_profissionals', 'list_especialidades', 'list_filials', 'list_area_atuacaos',
        	'sort_proced', 'direction_proced', 'limit', 'page_proced', 'ct_page_proced', 'total_procedimentos', 'sort_consulta', 'direction_consulta', 'limit', 'page_consulta', 'ct_page_consulta', 'total_consultas'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(PrestadoresRequest $request, $idClinica)
    {
        $prestador = Clinica::findOrFail($idClinica);
        $ct_clinica_obj        = $prestador->toJson();
        
        $prestador->update($request->all());
        
        //--atualizar usuário-----------------
        $usuario_id         = CVXRequest::post('responsavel_user_id');
        $usuario            = User::findorfail($usuario_id);

        $ct_user_obj        = $usuario->toJson();
        $usuario->name      = $request->input('name_responsavel');
        
        if ( (String)$request->input('change-password') === "1" ) {
            $usuario->password  = bcrypt($request->input('password'));
        }
        $usuario->save();

        //--salvar CNPJ------------------------
        $documento_ids = [];
        $cnpj_id                    = CVXRequest::post('cnpj_id');
        $documento                  = Documento::findorfail($cnpj_id);
        $ct_documento_obj           = $documento->toJson();
        $documento->tp_documento    = $request->input('tp_documento');
        $documento->te_documento    = UtilController::retiraMascara($request->input('te_documento'));
        $documento->save();
        $documento_ids = [$documento->id];

        //--salvar enderecos----------------------
        $endereco_ids = [];
        $endereco_id = CVXRequest::post('endereco_id');
        $endereco = Endereco::findorfail($endereco_id);
        $ct_endereco_obj           = $endereco->toJson();
        $endereco->nr_cep = UtilController::retiraMascara(CVXRequest::post('nr_cep'));
        $endereco->sg_logradouro = CVXRequest::post('sg_logradouro');
        $endereco->te_endereco = CVXRequest::post('te_endereco');
        $endereco->nr_logradouro = CVXRequest::post('nr_logradouro');
        $endereco->te_complemento = CVXRequest::post('te_complemento');
        $endereco->nr_latitude_gps = CVXRequest::post('nr_latitude_gps');
        $endereco->nr_longitute_gps = CVXRequest::post('nr_longitute_gps');
        $endereco->te_bairro = CVXRequest::post('te_bairro');

        $cidade = Cidade::where(['cd_ibge' => CVXRequest::post('cd_cidade_ibge')])->get()->first();
        $endereco->cidade()->associate($cidade);

        $endereco->save();
        $endereco_ids = [$endereco->id];

        //--salvar contatos----------------------
        $contato_ids = [];
        $contato_id = CVXRequest::post('contato_id');
        $contato = Contato::findorfail($contato_id);
        $ct_contato_obj           = $contato->toJson();
        $contato->tp_contato = CVXRequest::post('tp_contato_'.$contato_id);
        $contato->ds_contato = CVXRequest::post('ds_contato_'.$contato_id);
        $contato->save();
        $contato_ids = [$contato->id];

        # responsavel pela empresa
        $responsavel_id         = CVXRequest::post('responsavel_id');
        $responsavel            = Responsavel::findorfail($responsavel_id);
        $ct_responsavel_obj           = $responsavel->toJson();
        $responsavel->telefone  = $request->input('telefone_responsavel');
        $responsavel->save();

        $prestador = $this->setClinicaRelations($prestador, $documento_ids, $endereco_ids, $contato_ids);
        if ($prestador->save()) {

            # Registra log
            $user_obj           = $usuario->toJson();
            $clinica_obj        = $prestador->toJson();
            $documento_obj      = $documento->toJson();
            $endereco_obj       = $endereco->toJson();
            $responsavel_obj    = $responsavel->toJson();
            $contato_obj        = $contato->toJson();
            
            $titulo_log = 'Editar Clínica';
            $ct_log   = '"reg_anterior":'.'{"user":'.$ct_user_obj.', "clinica":'.$ct_clinica_obj.', "documento":'.$ct_documento_obj.', "endereco":'.$ct_endereco_obj.', "responsavel":'.$ct_responsavel_obj.', "contato":'.$ct_contato_obj.'}';
            $new_log  = '"reg_novo":'.'{"user":'.$user_obj.', "clinica":'.$clinica_obj.', "documento":'.$documento_obj.', "endereco":'.$endereco_obj.', "responsavel":'.$responsavel_obj.', "contato":'.$contato_obj.'}';
            $tipo_log = 3;
            
            $log = "{".$ct_log.",".$new_log."}";
            
            $reglog = new RegistroLogController();
            $reglog->registrarLog($titulo_log, $log, $tipo_log);
        }
        //$prestador->save();

        return redirect()->route('clinicas.index')->with('success', 'Prestador alterado com sucesso!');

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($idClinica)
    {
        $clinica = Clinica::findorfail($idClinica);
        $clinica_obj = $clinica->toJson();

        ########### STARTING TRANSACTION ############
        DB::beginTransaction();
        #############################################
        
        try{
            //--desabilita todos os contatos desse prestador------
            $clinica->load('contatos');
            $contatos = $clinica->contatos;
            //$clinica->contatos()->delete();
    
            foreach ($contatos as $contato) {
//                $contato->ds_contato = '(61) 00000-0000';
//                $contato->save();
            }
    
            //--desabilita todos os enderecos desse prestador----
            $clinica->load('enderecos');
            $enderecos = $clinica->enderecos;
            //$clinica->enderecos()->delete();
    
//            foreach ($enderecos as $endereco) {
//                $endereco->te_endereco = 'CANCELADO';
//                $endereco->save();
//            }
    
            //--desabilita todos os documentos desse prestador----
            $clinica->load('documentos');
            $documentos = $clinica->documentos;
            //$clinica->documentos()->delete();
    
//            foreach ($documentos as $documento) {
//                $documento->te_documento = '11111111111';
//                $documento->save();
//            }
    
            //--desabilita o responsavel por este prestador e o usuario tambem----
            $clinica->load('responsavel');
            $responsavel = $clinica->responsavel;

            if (!empty($responsavel)) {
				$responsavel->delete();
    
                $responsavel->load('user');
                $user_responsavel = $responsavel->user;
    
                if(!empty($user_responsavel)) {
                    $user_responsavel->cs_status = User::INATIVO;
                    $user_responsavel->save();
                }
            }
    
            //--desabilita o cadastro desse prestador----
            //$clinica->delete();
            $clinica->cs_status = Clinica::INATIVO;
            $clinica->save();
    
            //Atendimento::where('clinica_id', $idClinica)->delete();
    
            # registra log
            $titulo_log = 'Excluir Clínica';
            $tipo_log   = 4;
            
            $ct_log   = '"reg_anterior":'.'{}';
            $new_log  = '"reg_novo":'.'{"clinica":'.$clinica_obj.'}';
            
            $log = "{".$ct_log.",".$new_log."}";
            
            $reglog = new RegistroLogController();
            $reglog->registrarLog($titulo_log, $log, $tipo_log);
        } catch (\Exception $e) {
            ########### FINISHIING TRANSACTION ##########
            DB::rollback();
            #############################################
            return redirect()->route('clinicas.index')->with('error-alert', 'O prestador não foi excluído. Por favor, tente novamente.');
        }
        
        ########### FINISHIING TRANSACTION ##########
        DB::commit();
        #############################################

        return redirect()->route('clinicas.index')->with('success', 'Clínica excluída com sucesso!');
    }

    /**
     * Consulta para alimentar autocomplete
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfissionals(){

        $arResultado = array();

        $nm_profissional = CVXRequest::post('nm_profissional');
        $clinica_id = CVXRequest::post('clinica_id');

        $profissionals = Profissional::where('clinica_id', '=', $clinica_id)->where ( DB::raw ( 'to_str(nm_primario)' ), 'like', '%' . UtilController::toStr ( $nm_profissional ) . '%' )->orWhere ( DB::raw ( 'to_str(nm_secundario)' ), 'like', '%' . UtilController::toStr ( $nm_profissional ) . '%' )->get();

        foreach ($profissionals as $query)
        {
            $tipo_documento = $query->documentos()->first()->tp_documento;
            $nr_documento = $query->documentos()->first()->te_documento;

            $arResultado[] = [ 'id' =>  $query->id, 'value' => $query->nm_primario.' '.$query->nm_secundario.' ('.$tipo_documento.': '.$nr_documento.')' ];
        }

        return Response()->json($arResultado);
    }

    /**
     * Consulta para alimentar autocomplete
     *
     * @param string $term
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProcedimentos($termo){
        $arResultado = array();
        $procedimentos = Procedimento::where(DB::raw('to_str(cd_procedimento)'), 'like', '%'.UtilController::toStr($termo).'%')->orWhere(DB::raw('to_str(ds_procedimento)'), 'like', '%'.UtilController::toStr($termo).'%')->orderBy('ds_procedimento')->get();

        foreach ($procedimentos as $query)
        {
            $arResultado[] = [ 'id' =>  $query->id.' | '.$query->cd_procedimento .' | '.$query->ds_procedimento, 'value' => '('.$query->cd_procedimento.') '.$query->ds_procedimento ];
        }

        return Response()->json($arResultado);
    }

    /**
     * Consulta para alimentar autocomplete
     *
     * @param string $termo
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConsultas($termo){
        $arResultado = array();
        $consultas = Consulta::where(DB::raw('to_str(cd_consulta)'), 'like', '%'.UtilController::toStr($termo).'%')->orWhere(DB::raw('to_str(ds_consulta)'), 'like', '%'.UtilController::toStr($termo).'%')->orderBy('ds_consulta')->get();

        foreach ($consultas as $query)
        {
            $arResultado[] = [ 'id' => $query->id.' | '.$query->cd_consulta.' | '.$query->ds_consulta, 'value' => '('.$query->cd_consulta.') '.$query->ds_consulta ];
        }

        return Response()->json($arResultado);
    }

    //############# PERFORM RELATIONSHIP ##################
    /**
     * Perform relationship.
     *
     * @param  \App\Perfiluser  $perfiluser
     * @return \Illuminate\Http\Response
     */
    private function setClinicaRelations(Clinica $prestador, array $documento_ids, array $endereco_ids, array $contato_ids)
    {
        $prestador->documentos()->sync($documento_ids);
        $prestador->enderecos()->sync($endereco_ids);
        $prestador->contatos()->sync($contato_ids);

        return $prestador;
    }

    /**
     * Perform relationship.
     *
     * @param  \App\Profissional  $profissional
     * @return \Illuminate\Http\Response
     */
    private function setProfissionalRelations(Profissional $profissional, array $documento_ids, array $contatos_ids, array $especialidade_ids, array $filial_ids, array $area_atuacao_ids, Clinica $clinica)
    {
        $profissional->documentos()->sync($documento_ids);
        $profissional->especialidades()->sync($especialidade_ids);
        $profissional->area_atuacaos()->sync($area_atuacao_ids);

        if( in_array('all', $filial_ids) ) {
            $obj = [];
            foreach ($clinica->filials()->where('cs_status','A')->get() as $filial) {
                $obj[] = $filial->id;
            }

            $profissional->filials()->sync($obj);
        }
        else {
            $profissional->filials()->sync($filial_ids);
        }

        return $profissional;
    }
    
    /**
     * Perform relationship.
     *
     * @param  \App\Profissional  $profissional
     * @return \Illuminate\Http\Response
     */
    private function setAtendimentoRelations(Atendimento $atendimento, array $filial_ids)
    {
    	if( in_array('all', $filial_ids) ) {
            $obj = [];
            foreach ($atendimento->clinica->filials()->where('cs_status','A')->get() as $filial) {
                $obj[] = $filial->id;
            }

            $atendimento->filials()->sync($obj);
        }
        else {
            $atendimento->filials()->sync($filial_ids);    
        }
        
    
    	return $atendimento;
    }

    //############# AJAX SERVICES ##################
    /**
     * addProfissionalStore a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addProfissionalStore(Request $request)
    {
        $clinica_id = CVXRequest::post('clinica_id');
        $clinica = Clinica::findorfail($clinica_id);

        $profissional_id = CVXRequest::post('profissional_id');
        if ($profissional_id != '') {
            $profissional = Profissional::findorfail($profissional_id);
            $profissional->load('documentos');
        }
        $ct_profissional_obj = $profissional_id != '' ? $profissional->toJson() : "[]";

        if (isset($profissional) && isset($profissional->documentos) && sizeof($profissional->documentos) > 0) {
            $documento_id = $profissional->documentos[0]->id;
            $documento = Documento::findorfail($documento_id);
            $ct_documento_obj = $documento->toJson();

            $documento->tp_documento = CVXRequest::post('tp_documento');
            $documento->te_documento = CVXRequest::post('te_documento');
            $documento->save();

            $documento_ids = [$documento->id];
        } else {
            $documento = new Documento();
            $documento->tp_documento =  CVXRequest::post('tp_documento');
            $documento->te_documento =  CVXRequest::post('te_documento');
            $documento->save();
            $documento_ids = [$documento->id];
            $ct_documento_obj = "[]";
        }

        $contatos_ids = [];

        if (!isset($profissional)) {
            $profissional = new Profissional();
        }
        $profissional->nm_primario = CVXRequest::post('nm_primario');
        $profissional->nm_secundario = CVXRequest::post('nm_secundario');
        $profissional->cs_sexo = CVXRequest::post('cs_sexo');
        $profissional->dt_nascimento = preg_replace("/(\d+)\D+(\d+)\D+(\d+)/","$3-$2-$1", CVXRequest::post('dt_nascimento'));
        $profissional->clinica_id = intval($clinica_id);
        //$profissional->especialidade_id = CVXRequest::post('especialidade_id');
        $especialidade_ids = CVXRequest::post('especialidade_profissional');
        $filial_ids = CVXRequest::post('filial_profissional');
        $area_atuacao_ids =  is_null(CVXRequest::post('area_atuacao_profissional')) ? [] : CVXRequest::post('area_atuacao_profissional');
        
        $profissional->tp_profissional = CVXRequest::post('tp_profissional');
        $profissional->cs_status = CVXRequest::post('cs_status');

        if ($profissional->save()) {

            # registra log
            
            $profissional_obj           = $profissional->toJson();
            $documento_obj              = $documento->toJson();
            
            $titulo_log                 = $profissional_id != '' ? 'Editar Profissional' : 'Adicionar Profissional';
            $tipo_log                   = $profissional_id != '' ? 3 : 1;
            
            $ct_log   = '"reg_anterior":'.'{"profissional":'.$profissional_obj.', "documento":'.$documento_obj.'}';
            $new_log  = '"reg_novo":'.'{"profissional":'.$ct_profissional_obj.', "documento":'.$ct_documento_obj.'}';
            
            $log = "{".$ct_log.",".$new_log."}";
            
            $reglog = new RegistroLogController();
            $reglog->registrarLog($titulo_log, $log, $tipo_log);

        } else {
            return response()->json(['status' => false, 'mensagem' => 'O Profissional não foi salvo. Por favor, tente novamente.']);
        }

        $profissional = $this->setProfissionalRelations($profissional, $documento_ids, $contatos_ids, $especialidade_ids, $filial_ids, $area_atuacao_ids, $clinica);
        $profissional->save();

        $profissional->load('especialidades');

        return response()->json(['status' => true, 'mensagem' => 'O Profissional foi salvo com sucesso!', 'profissional' => $profissional->toJson()]);
    }

    /**
     * viewProfissionalShow a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function viewProfissionalShow()
    {
        $profissional_id = CVXRequest::post('profissional_id');
        $profissional = Profissional::findorfail($profissional_id);
        $profissional->load('documentos');
        $profissional->load('especialidades');
        $profissional->load('filials');
        $profissional->load('area_atuacaos');

        return response()->json(['status' => true, 'mensagem' => '', 'profissional' => $profissional->toJson()]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deleteProfissionalDestroy()
    {
        $profissional_id = CVXRequest::post('profissional_id');
        $profissional = Profissional::findorfail($profissional_id);
        $profissional->cs_status = 'I';

        if ($profissional->save()) {

            # registra log
            $profissional_obj           = $profissional->toJson();
            
            # registra log
            
            $titulo_log = 'Excluir Profissional';
            $tipo_log   = 4;
            
            $ct_log   = '"reg_anterior":'.'{}';
            $new_log  = '"reg_novo":'.'{"profissional":'.$profissional_obj.'}';
            
            $log = "{".$ct_log.",".$new_log."}";
            
            $reglog = new RegistroLogController();
            $reglog->registrarLog($titulo_log, $log, $tipo_log);

        } else {
            return response()->json(['status' => false, 'mensagem' => 'O Profissional não foi removido. Por favor, tente novamente.']);
        }

        return response()->json(['status' => true, 'mensagem' => 'O Profissional foi removido com sucesso!', 'profissional' => $profissional->toJson()]);
    }

    /**
     * ' a newly created resource in storage.
     *
     * @param  Clinica  $clinica
     * @param  PrecificacaoConsultaRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function precificacaoConsultaStore(Clinica $clinica, PrecificacaoConsultaRequest $request)
    {
		$data_vigencia = UtilController::getDataRangeTimePickerToCarbon($request->get('data-vigencia'));

// 		dd($request->all(), $data_vigencia);

        foreach ($request->list_profissional_consulta as $profissionalId) {
			$atendimento = Atendimento::where([
				'clinica_id' => $clinica->id,
				'profissional_id' => $profissionalId,
				'consulta_id' => $request->consulta_id,
				'cs_status' => 'A'
			])->first();

			if(is_null($atendimento)) {
				$atendimento = new Atendimento();
				$atendimento->clinica_id = $clinica->id;
				$atendimento->profissional_id = $profissionalId;
				$atendimento->consulta_id = $request->consulta_id;
				$atendimento->ds_preco =  $request->descricao_consulta;
				$atendimento->cs_status = 'A';
				$atendimento->save();
			}

			$preco = Preco::where(['atendimento_id' => $atendimento->id, 'plano_id' => $request->plano_id, 'cs_status' => 'A'])
				->where('data_inicio', '<=', date('Y-m-d'))
				->where('data_fim', '>=', date('Y-m-d'));
// 			dd($atendimento);
			if($preco->exists()) {
				$profissional = Profissional::findorfail($atendimento->profissional_id);
// 				dd($profissional);
				$error[] = "Preço {$atendimento->ds_preco} - {$profissional->nm_primario}, plano {$preco->first()->plano->ds_plano} já cadastrado";
				$mensagem = "Preço ".$atendimento->ds_preco." - ".$profissional->nm_primario.", plano ".$preco->first()->plano->ds_plano." já cadastrado";
				
				return redirect()->route('clinicas.edit', $clinica->id)->with('error-alert', $mensagem);
			} else {
				$preco = new Preco();
				$preco->cd_preco = $atendimento->id;
				$preco->atendimento_id = $atendimento->id;
				$preco->plano_id = $request->plano_id;
				$preco->tp_preco_id = TipoPreco::INDIVIDUAL;
				$preco->cs_status = 'A';
				$preco->data_inicio = $data_vigencia['de'];
				$preco->data_fim = $data_vigencia['ate'];
				$preco->vl_comercial = $request->vl_com_consulta;
				$preco->vl_net = $request->vl_net_consulta;

				$preco->save();
			}
            
            # registra log
			$preco_obj          = $preco->toJson();
            $atendimento_obj    = $atendimento->toJson();
            
            $titulo_log = 'Adicionar Consulta';
            $ct_log   = '"reg_anterior":'.'{}';
            $new_log  = '"reg_novo":'.'{"atendimento":'.$atendimento_obj.', "preco":'.$preco_obj.'}';
            $tipo_log = 1;
            
            $log = "{".$ct_log.",".$new_log."}";
            
            $reglog = new RegistroLogController();
            $reglog->registrarLog($titulo_log, $log, $tipo_log);
        }

		if(isset($error) && !empty($error)) {
			return redirect()->back()->with('error-alert', implode('<br>', $error));
		}

        return redirect()->back()->with('success', 'A precificação da consulta foi salva com sucesso!');
    }

    /**
     * precificacaoConsultaUpdate a newly created resource in storage.
     *
     * @param  Clinica  $clinica
     * @return \Illuminate\Http\Response
     */
    public function precificacaoConsultaUpdate(Clinica $clinica, PrecificacaoConsultaRequest $request)
    {
        $atendimento = Atendimento::findOrFail($request->atendimento_id);
        $atendimento->profissional_id = $request->profissional_id;
        $atendimento->ds_preco =  $request->ds_consulta;

        $atendimento->save();

         # registra log
        $atendimentoOld    = json_encode( $atendimento->getOriginal() );
        $atendimentoNew    = json_encode( $atendimento->getAttributes() );
        
        $titulo_log = 'Editar Consulta';
        $ct_log   = '"reg_anterior":'.'{"atendimento":'.$atendimentoOld.'}';
        $new_log  = '"reg_novo":'.'{"atendimento":'.$atendimentoNew.'}';
        $tipo_log = 3;
        
        $log = "{".$ct_log.",".$new_log."}";
        
        $reglog = new RegistroLogController();
        $reglog->registrarLog($titulo_log, $log, $tipo_log);
        
        return redirect()->back()->with('success', 'A precificação da consulta foi salva com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function precificacaoConsultaDestroy(PrecificacaoConsultaRequest $request)
    {
        $atendimento = Atendimento::findorfail( $request->atendimento_id );
        $atendimento->cs_status = 'I';
        $atendimento->save();

        # registra log
        $atendimento_obj = $atendimento->toJson();
        
        $titulo_log = 'Excluir Consulta';
        $ct_log   = '"reg_anterior":'.'{}';
        $new_log  = '"reg_novo":'.'{"atendimento":'.$atendimento_obj.'}';
        $tipo_log = 4;
        
        $log = "{".$ct_log.",".$new_log."}";
        
        $reglog = new RegistroLogController();
        $reglog->registrarLog($titulo_log, $log, $tipo_log);

        return response()->json(['status' => true, 'mensagem' => 'A Consulta foi removida com sucesso!', 'atendimento' => $atendimento->toJson()]);
    }

    /**
     * precificacaoProcedimentoStore a newly created resource in storage.
     *
     * @param  Clinica  $clinica
     * @param  PrecificacaoProcedimentoRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function precificacaoProcedimentoStore(Clinica $clinica, PrecificacaoProcedimentoRequest $request)
    {
		$data_vigencia = UtilController::getDataRangeTimePickerToCarbon($request->get('data-vigencia'));

		$atendimento = Atendimento::where(['clinica_id' => $clinica->id, 'procedimento_id' => $request->procedimento_id, 'cs_status' => 'A'])->first();

		if(is_null($atendimento)) {
			$atendimento = new Atendimento();
			$atendimento->clinica_id = $clinica->id;
			$atendimento->procedimento_id = $request->procedimento_id;
			$atendimento->ds_preco =  $request->descricao_procedimento;
			$atendimento->cs_status = 'A';
			$atendimento->save();
		}

		$preco = Preco::where(['atendimento_id' => $atendimento->id, 'plano_id' => $request->plano_id, 'cs_status' => 'A']);

		if($preco->exists()) {
// 			return redirect()->back()->with('error-alert', 'O plano já está cadastrado. Por favor, tente novamente.');
			
			$mensagem = "O plano já está cadastrado. Por favor, tente novamente.";
			
			return redirect()->route('clinicas.edit', $clinica->id)->with('error-alert', $mensagem);
		}

		$preco = new Preco();
		$preco->cd_preco = $atendimento->id;
		$preco->atendimento_id = $atendimento->id;
		$preco->plano_id = $request->plano_id;
		$preco->tp_preco_id = TipoPreco::INDIVIDUAL;
		$preco->cs_status = 'A';
		$preco->data_inicio = $data_vigencia['de'];
		$preco->data_fim = $data_vigencia['ate'];
		$preco->vl_comercial = $request->vl_com_procedimento;
		$preco->vl_net = $request->vl_net_procedimento;

		$preco->save();

		$plano_id = $request->plano_id;
		$plano = Plano::findorfail($plano_id);
		
        # registra log
		$preco->plano       = $plano;
		$preco_obj          = $preco->toJson();
		$atendimento_obj    = $atendimento->toJson();
		//$plano_obj          = $plano->toJson();
        $titulo_log         = 'Adicionar Preço';
        $tipo_log           = 1;
        
        $ct_log   = '"reg_anterior":'.'{}';
        $new_log  = '"reg_novo":'.'{"preco":'.$preco_obj.', "atendimento":'.$atendimento_obj.'}';

        $log = "{".$ct_log.",".$new_log."}";
        
        $reglog = new RegistroLogController();
        $reglog->registrarLog($titulo_log, $log, $tipo_log);

        return redirect()->back()->with('success', 'A precificação da procedimento foi salva com sucesso!');
    }

    /**
     * precificacaoProcedimentoUpdate a newly created resource in storage.
     *
     * @param  Clinica  $clinica
     * @param  PrecificacaoProcedimentoRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function precificacaoProcedimentoUpdate(Clinica $clinica, PrecificacaoProcedimentoRequest $request)
    {
        $atendimento = Atendimento::findOrFail($request->atendimento_id);
        $atendimento->ds_preco =  $request->ds_procedimento;
        $atendimento = $this->setAtendimentoRelations($atendimento, $request->atendimento_filial);

        $atendimento->save();

         # registra log
        $atendimentoOld    = json_encode( $atendimento->getOriginal() );
        $atendimentoNew    = json_encode( $atendimento->getAttributes() );
        
        $titulo_log = 'Editar Procedimento';
        $ct_log   = '"reg_anterior":'.'{"atendimento":'.$atendimentoOld.'}';
        $new_log  = '"reg_novo":'.'{"atendimento":'.$atendimentoNew.'}';
        $tipo_log = 3;
        
        $log = "{".$ct_log.",".$new_log."}";
        
        $reglog = new RegistroLogController();
        $reglog->registrarLog($titulo_log, $log, $tipo_log);

        return redirect()->back()->with('success', 'A precificação da procedimento foi salva com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param PrecificacaoProcedimentoRequest $request
     * @return \Illuminate\Http\Response
     */
    public function precificacaoProcedimentoDestroy(PrecificacaoProcedimentoRequest $request)
    {
        $atendimento = Atendimento::findorfail( $request->atendimento_id );
        $atendimento->cs_status = 'I';
        $atendimento->save();
        
        # registra log
        $atendimento_obj = $atendimento->toJson();
        
        $titulo_log = 'Excluir Procedimento';
        $ct_log   = '"reg_anterior":'.'{}';
        $new_log  = '"reg_novo":'.'{"atendimento":'.$atendimento_obj.'}';
        $tipo_log = 4;
        
        $log = "{".$ct_log.",".$new_log."}";
        
        $reglog = new RegistroLogController();
        $reglog->registrarLog($titulo_log, $log, $tipo_log);

        return response()->json(['status' => true, 'mensagem' => 'A Procedimento foi removida com sucesso!', 'atendimento' => $atendimento->toJson()]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deleteProcedimentoDestroy()
    {
        $atendimento_id = CVXRequest::post('atendimento_id');
        $atendimento = Atendimento::findorfail($atendimento_id);
        $atendimento->cs_status = 'I';

        if ($atendimento->save()) {
            
            # registra log
            $atendimento_obj = $atendimento->toJson();
            
            $titulo_log = 'Excluir Procedimento';
            $ct_log   = '"reg_anterior":'.'{}';
            $new_log  = '"reg_novo":'.'{"atendimento":'.$atendimento_obj.'}';
            $tipo_log = 4;
            
            $log = "{".$ct_log.",".$new_log."}";
            
            $reglog = new RegistroLogController();
            $reglog->registrarLog($titulo_log, $log, $tipo_log);

        } else {
            return response()->json(['status' => false, 'mensagem' => 'O Atendimento não foi removido. Por favor, tente novamente.']);
        }

        return response()->json(['status' => true, 'mensagem' => 'O Atendimento foi removido com sucesso!', 'atendimento' => $atendimento->toJson()]);
    }

    /**
     * Consulta Cidade através da UF
     *
     * @param  \Illuminate\Http\Request  $request

     */
    public function consultaCidade() {
        $output = null;

        $uf = CVXRequest::get('uf');
        $term = CVXRequest::get('term');

        if ( !empty($uf) ) { 
            $cidades = Cidade::where('sg_estado',$uf)->whereRaw( "UPPER(nm_cidade) LIKE UPPER('%$term%')")->orderBy('nm_cidade')->select('id','nm_cidade as label','nm_cidade as value','cd_ibge')->get();

            return response()->json($cidades);
        }
        
        return response()->json(['status' => false, 'mensagem' => 'UF não informada.']);
    }
    
    /**
     * Gera relatório Xls a partir de parâmetros de consulta do fluxo básico.
     *
     */
    public function geraListaPrestadoresAtivosXls()
    {
        
        Excel::create('DRHJ_RELATORIO_PRESTADORES_ATIVOS_' . date('d-m-Y~H_i_s'), function ($excel) {
            $excel->sheet('Consultas', function ($sheet) {
                
                // Font family
                $sheet->setFontFamily('Comic Sans MS');
                
                // Set font with ->setStyle()`
                $sheet->setStyle(array(
                    'font' => array(
                        'name' => 'Calibri',
                        'size' => 12,
                        'bold' => false
                    )
                ));
                
                $cabecalho = array('Data' => date('d-m-Y H:i'));

                DB::enableQueryLog();
                $list_prestadores = Clinica::with(['contatos', 'documentos', 'responsavel', 'responsavel.user', 'enderecos.cidade'])
                    ->distinct()
                    ->leftJoin('responsavels',			function($join1) { $join1->on('clinicas.responsavel_id', '=', 'responsavels.id');})
                    ->leftJoin('users',					function($join2) { $join2->on('responsavels.user_id', '=', 'users.id');})
                    ->select('clinicas.id', 'clinicas.nm_razao_social', 'clinicas.nm_fantasia', 'clinicas.created_at', 'clinicas.updated_at', 'users.name As nome_responsavel', 'users.email As email_responsavel')
                    ->orderby('clinicas.nm_razao_social', 'asc');

                if( !is_null(Request::input('inlineCheckboxNovos')) ) {
                    $list_prestadores->where(['clinicas.cs_status' => 'I'])->whereDate('clinicas.created_at', '=', DB::raw('"clinicas"."updated_at"::date'));
                }

                if( !is_null(Request::input('inlineCheckboxAtivos')) ) {
                    $list_prestadores->where(['clinicas.cs_status' => 'A']);
                }

                if( !is_null(Request::input('inlineCheckboxInativos')) ) {
                    $list_prestadores->where(['clinicas.cs_status' => 'I'])->whereDate('clinicas.created_at', '<>', DB::raw('"clinicas"."updated_at"::date'));
                }

                $list_prestadores = $list_prestadores->get();

                //$queries = DB::getQueryLog();
                //dd($queries);
                
//                 dd($list_prestadores);
                
//                $sheet->setColumnFormat(array(
//                    'F6:F'.(sizeof($list_prestadores)+6) => '""00"." 000"."000"/"0000-00'
//                ));
                
                $sheet->loadView('clinicas.prestadores_ativos_excel', compact('list_prestadores', 'cabecalho'));
            });
        })->export('xls');
    }
}