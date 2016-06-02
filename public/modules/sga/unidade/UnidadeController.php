<?php
namespace modules\sga\unidade;

use \Novosga\SGAContext;
use \Novosga\Util\Arrays;
use \Novosga\Http\AjaxResponse;
use \Novosga\Controller\ModuleController;
use \Novosga\Business\AtendimentoBusiness;

/**
 * UnidadeController
 * 
 * Controlador do módulo de configuração da unidade
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class UnidadeController extends ModuleController {
    
    public function index(SGAContext $context) {
        $unidade = $context->getUnidade();
        $this->app()->view()->set('unidade', $unidade);
        if ($unidade) {
            $locais = $this->em()->getRepository('Novosga\Model\Local')->findAll();
            if (sizeof($locais)) {
                $local = $locais[0];
                // atualizando relacionamento entre unidade e servicos/subservicos
                $conn = $this->em()->getConnection();
                $conn->executeUpdate("
                    INSERT INTO uni_serv 
                        (unidade_id, servico_id, local_id, nome, sigla, status, peso)
                    SELECT 
                        :unidade, id, :local, nome, 'A', 0, peso 
                    FROM 
                        servicos 
                    WHERE 
                        /*macro_id IS NULL AND*/
                        id NOT IN (SELECT servico_id FROM uni_serv WHERE unidade_id = :unidade)
                ", array('unidade' => $unidade->getId(), 'local' => $local->getId()));
                // todos servicos da unidade
                $query = $this->em()->createQuery("
                    SELECT 
                        e 
                    FROM 
                        Novosga\Model\ServicoUnidade e 
                    WHERE 
                        e.unidade = :unidade 
                    ORDER BY 
                        e.nome
                ");
                $query->setParameter('unidade', $unidade->getId());
                $this->app()->view()->set('servicos', $query->getResult());
                // locais disponiveis
                $query = $this->em()->createQuery("SELECT e FROM Novosga\Model\Local e ORDER BY e.nome");
                $this->app()->view()->set('locais', $query->getResult());
            }
        }
    }
    
    public function update_impressao(SGAContext $context) {
        $impressao = (int) Arrays::value($_POST, 'impressao');
        $mensagem = Arrays::value($_POST, 'mensagem', '');
        $unidade = $context->getUser()->getUnidade();
        if ($unidade) {
            $query = $this->em()->createQuery("UPDATE Novosga\Model\Unidade e SET e.statusImpressao = :status, e.mensagemImpressao = :mensagem WHERE e.id = :unidade");
            $query->setParameter('status', $impressao);
            $query->setParameter('mensagem', $mensagem);
            $query->setParameter('unidade', $unidade->getId());
            if ($query->execute()) {
                // atualizando sessao
                $unidade = $this->em()->find('Novosga\Model\Unidade', $unidade->getId());
                $context->setUnidade($unidade);
            }
        }
        $response = new AjaxResponse(true);
        echo $response->toJson();
        exit();
    }
    
    private function change_status(SGAContext $context, $status) {
        $servico_id = (int) Arrays::value($_POST, 'id');
        $unidade = $context->getUser()->getUnidade();
        if (!$servico_id || !$unidade) {
            return false;
        }
        $query = $this->em()->createQuery("UPDATE Novosga\Model\ServicoUnidade e SET e.status = :status WHERE e.unidade = :unidade AND e.servico = :servico");
        $query->setParameter('status', $status);
        $query->setParameter('servico', $servico_id);
        $query->setParameter('unidade', $unidade->getId());
        return $query->execute();
    }
    
    public function habilita_servico(SGAContext $context) {
        $response = new AjaxResponse();
        $response->success = $this->change_status($context, 1);
        $context->response()->jsonResponse($response);
    }
    
    public function desabilita_servico(SGAContext $context) {
        $response = new AjaxResponse();
        $response->success = $this->change_status($context, 0);
        $context->response()->jsonResponse($response);
    }
    
    public function update_servico(SGAContext $context) {
        $response = new AjaxResponse();
        $id = (int) $context->request()->getParameter('id');
        try {
            $query = $this->em()->createQuery("SELECT e FROM Novosga\Model\ServicoUnidade e WHERE e.unidade = :unidade AND e.servico = :servico");
            $query->setParameter('servico', $id);
            $query->setParameter('unidade', $context->getUser()->getUnidade()->getId());
            $su = $query->getSingleResult();

            $sigla = $context->request()->getParameter('sigla');
            $nome = $context->request()->getParameter('nome');
            $local = $this->em()->find("Novosga\Model\Local", (int) $context->request()->getParameter('local'));
            
            $su->setSigla($sigla);
            $su->setNome($nome);
            if ($local) {
                $su->setLocal($local);
            }
            $this->em()->merge($su);
            $this->em()->flush();
            $response->success = true;
        } catch (\Exception $e) {
            $response->message = $e->getMessage();
        }
        $context->response()->jsonResponse($response);
    }
    
    public function reverte_nome(SGAContext $context) {
        $response = new AjaxResponse();
        $id = (int) $context->request()->getParameter('id');
        $servico = $this->em()->find('Novosga\Model\Servico', $id);
        if ($servico) {
            $query = $this->em()->createQuery("UPDATE Novosga\Model\ServicoUnidade e SET e.nome = :nome WHERE e.unidade = :unidade AND e.servico = :servico");
            $query->setParameter('nome', $servico->getNome());
            $query->setParameter('servico', $servico->getId());
            $query->setParameter('unidade', $context->getUser()->getUnidade()->getId());
            $query->execute();
            $response->data['nome'] = $servico->getNome();
            $response->success = true;
        } else {
            $response->message = _('Serviço inválido');
        }
        $context->response()->jsonResponse($response);
    }
    
    public function acumular_atendimentos(SGAContext $context) {
        $response = new AjaxResponse();
        $unidade = $context->getUnidade();
        if ($unidade) {
            try {
                $ab = new AtendimentoBusiness($this->em());
                $ab->acumularAtendimentos($unidade);
                $response->success = true;
            } catch (\Exception $e) {
                $response->message = $e->getMessage();
            }
        } else {
            $response->message = _('Nenhum unidade definida');
        }
        $context->response()->jsonResponse($response);
    }
    
}
