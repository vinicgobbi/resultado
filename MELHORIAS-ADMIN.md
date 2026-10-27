# Melhorias da área admin

A área admin deixou de ser só a troca de cores dos candidatos e virou um
**painel de operação da apuração**: o lugar para saber se está tudo
funcionando e agir sem precisar de acesso ao servidor.

Itens marcados já foram feitos.

## 1. Painel de status (página inicial do admin)

- [x] Bloqueio do TSE (HTTP 429) e quando ele volta
- [x] Fila: jobs aguardando, jobs com falha e a mensagem da última falha
- [x] Mapa: se a geometria está gerada
- [x] Notificações push: se as chaves VAPID estão configuradas
- [x] Eleições recentes com andamento, resultados finais (ex.: "1.234 de
      5.570") e última sincronização
- [x] Atualização automática: a cada 3 s enquanto uma tarefa roda, senão a
      cada 30 s

## 2. Ações que antes exigiam o terminal

- [x] Sincronizar agora (`tse:sincronizar`)
- [x] Ressincronizar uma eleição por completo (botão na linha da eleição)
- [x] Descobrir eleições novas (`tse:descobrir`)
- [x] Importar eleições encerradas (`tse:importar`)
- [x] Baixar fotos da eleição atual (`tse:fotos`)
- [x] Baixar fotos de uma eleição (botão na linha da eleição)
- [x] Baixar fotos das eleições encerradas (`tse:fotos --encerradas`)
- [x] Gerar o mapa (`mapa:baixar`)
- [x] Limpar o cache dos resultados (`resultados:limpar-cache`)
- [x] Histórico das execuções: quem iniciou, estado e saída do comando ao vivo
- [x] Fila própria (`database-admin`) com worker separado, para tarefas
      longas não atrasarem as notificações
- [x] Trava no `tse:sincronizar`: a execução manual nunca roda junto com a
      agendada (evita notificação duplicada)
- [x] Mesma tarefa não pode ser enfileirada duas vezes
- [ ] Botão para cancelar uma tarefa em execução
- [ ] Botão para tentar de novo / limpar os jobs com falha da fila

## 3. Notificações

- [x] Número de navegadores inscritos e de resultados seguidos (no cartão
      de notificações push do painel)
- [ ] Resultados mais seguidos (ex.: "Presidente · Brasil: 1.200")
- [ ] Mensagens enviadas e falhas por período
- [ ] Aviso manual para quem segue um resultado ou para todos (ex.: "o TSE
      está atrasando a divulgação")
- [ ] Notificação de teste para o próprio aparelho

## 4. Aviso no site

- [ ] Faixa no topo de todas as páginas, editável pelo admin (ex.: "A
      apuração começa às 17h", "Instabilidade no site do TSE")
- [ ] Data de início e fim para o aviso aparecer e sumir sozinho
- [ ] Níveis de destaque (informação, atenção)

## 5. Candidatos

- [x] Trocar a cor de um candidato (e voltar à cor automática)
- [ ] Corrigir o nome de urna
- [ ] Enviar uma foto manualmente, quando o TSE não tem ou está bloqueando
- [ ] Editar as cores padrão dos partidos (hoje fixas em `config/partidos.php`)

## 6. Eleições

- [ ] Escolher qual eleição aparece na página inicial
- [ ] Esconder eleições do arquivo público
- [ ] Reabrir a sincronização de uma eleição já concluída
- [ ] Editar o nome de exibição de uma eleição

## Antes de publicar as mudanças do painel

- Rodar as migrações (`push_follows`, `push_messages`, `maintenance_runs`)
- Confirmar que o agendador (`schedule:run` no cron ou `schedule:work`) está
  ativo no servidor: é ele que inicia o worker da fila `admin`; sem isso, as
  tarefas ficam paradas em "Na fila"
- Conferir se o servidor tem alguns GB livres antes de baixar as fotos das
  eleições municipais (cerca de 3,4 GB só em 2024)
