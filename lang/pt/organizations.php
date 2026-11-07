<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Candidatura recebida: :name',
    'applied_body' => 'Recebemos o pedido relativo a :name. Um administrador irá verificar a identidade da organização e a sua autoridade para a representar. Pode acompanhar o andamento do processo no seu painel de gestão.',
    'admin_subject' => 'É necessária uma revisão da organização: :name',
    'admin_body' => 'O pedido relativo a :name está a aguardar análise. Abra a página administrativa privada para analisar as provas e a declaração de representação.',
    'verify_subject' => 'Verifica-se se :name existe',
    'verify_body' => 'A verificação de identidade para :name está concluída. O seu perfil público está pronto: partilhe o URL e associe as suas campanhas a partir do seu painel de gestão. A verificação não implica qualquer endosso das alegações das campanhas.',
    'reject_subject' => 'Atualização da aplicação: :name',
    'reject_body' => 'O pedido relativo a :name não foi aprovado. Consulte o motivo abaixo, atualize o seu perfil e volte a submeter o pedido explicitamente quando estiver pronto.',
    'suspend_subject' => 'Perfil suspenso: :name',
    'suspend_body' => 'O perfil público de :name foi suspenso. O seu painel de gestão explica o estado; contacte o apoio ao utilizador para obter ajuda. Isto não remove automaticamente as suas petições.',
    'restore_subject' => 'Perfil restaurado: :name',
    'restore_body' => 'A suspensão do :name foi levantada após análise. Abra o seu perfil ou o painel de gestão para verificar o estado atual da verificação.',
    'donation_subject' => 'Link para doações aprovado: :name',
    'donation_body' => 'O link externo para doações destinado a :name foi aprovado. Os apoiantes podem aceder ao mesmo a partir do perfil verificado. A alteração do destino exigirá uma nova análise.',
    'action' => 'Abrir o FreeCause',
];

return $strings;
