# Tarefas

O que já foi feito e o que falta, por frente de trabalho. Itens marcados
estão prontos.

- [Área admin](#área-admin)
- [Auditoria das urnas](#auditoria-das-urnas)
- [Outras pendências](#outras-pendências)

## Área admin

A área admin deixou de ser só a troca de cores dos candidatos e virou um
**painel de operação da apuração**: o lugar para saber se está tudo
funcionando e agir sem precisar de acesso ao servidor.

### 1. Painel de status (página inicial do admin)

- [x] Bloqueio do TSE (HTTP 429) e quando ele volta
- [x] Fila: jobs aguardando, jobs com falha e a mensagem da última falha
- [x] Mapa: se a geometria está gerada
- [x] Notificações push: se as chaves VAPID estão configuradas
- [x] Eleições recentes com andamento, resultados finais (ex.: "1.234 de
      5.570") e última sincronização
- [x] Atualização automática: a cada 3 s enquanto uma tarefa roda, senão a
      cada 30 s

### 2. Ações que antes exigiam o terminal

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

### 3. Notificações

- [x] Número de navegadores inscritos e de resultados seguidos (no cartão
      de notificações push do painel)
- [ ] Resultados mais seguidos (ex.: "Presidente · Brasil: 1.200")
- [ ] Mensagens enviadas e falhas por período
- [ ] Aviso manual para quem segue um resultado ou para todos (ex.: "o TSE
      está atrasando a divulgação")
- [ ] Notificação de teste para o próprio aparelho

### 4. Aviso no site

- [ ] Faixa no topo de todas as páginas, editável pelo admin (ex.: "A
      apuração começa às 17h", "Instabilidade no site do TSE")
- [ ] Data de início e fim para o aviso aparecer e sumir sozinho
- [ ] Níveis de destaque (informação, atenção)

### 5. Candidatos

- [x] Trocar a cor de um candidato (e voltar à cor automática)
- [ ] Corrigir o nome de urna
- [ ] Enviar uma foto manualmente, quando o TSE não tem ou está bloqueando
- [ ] Editar as cores padrão dos partidos (hoje fixas em `config/partidos.php`)

### 6. Eleições

- [ ] Escolher qual eleição aparece na página inicial
- [ ] Esconder eleições do arquivo público
- [ ] Reabrir a sincronização de uma eleição já concluída
- [ ] Editar o nome de exibição de uma eleição

### Antes de publicar as mudanças do painel

- Rodar as migrações (`push_follows`, `push_messages`, `maintenance_runs`)
- Confirmar que o agendador (`schedule:run` no cron ou `schedule:work`) está
  ativo no servidor: é ele que inicia o worker da fila `admin`; sem isso, as
  tarefas ficam paradas em "Na fila"
- Conferir se o servidor tem alguns GB livres antes de baixar as fotos das
  eleições municipais (cerca de 3,4 GB só em 2024)

## Auditoria das urnas

> Projeto futuro, de longo prazo.

**Objetivo:** somar os votos de cada urna do Brasil e conferir se o total
bate com o resultado final divulgado pelo TSE, num lugar à parte do site
(uma seção avançada de transparência).

**Por quê:** garantir a transparência dos dados de forma acessível a
todos. Hoje essa conferência exige baixar e cruzar centenas de milhares de
arquivos; a ideia é que qualquer pessoa veja o resultado da conferência,
entenda como foi feita e possa refazê-la.

### Princípios

- **Explicar, não alarmar:** uma diferença pode ter causa legítima (seção
  agregada a outra, votos anulados de candidato com registro indeferido,
  retotalização). Cada diferença encontrada precisa vir com a explicação
  possível, em linguagem simples, e sem afirmar fraude.
- **Reproduzível:** a metodologia, o código e os dados usados ficam
  públicos, e um comando refaz toda a conferência.
- **Acessível:** a mesma linguagem simples e os mesmos cuidados de
  acessibilidade do resto do site; números grandes sempre com contexto.

### 1. Pesquisa das fontes

- [ ] Mapear os arquivos por seção do Portal de Resultados ("Arquivos de
      Urna"): boletim de urna (BU), assinatura e demais arquivos, formato e
      endereço de cada um
- [ ] Mapear o conjunto "Boletim de urna" do Portal de Dados Abertos (ZIP
      por estado e turno) e os arquivos "Hash SHA2" que o acompanham
- [ ] Entender quando cada fonte é publicada (durante a apuração ou só
      depois) e por quanto tempo fica disponível
- [ ] Entender as seções agregadas (uma seção que funciona junto com outra
      e não aparece com o próprio número)
- [ ] Estimar o volume: número de seções por eleição, tamanho dos arquivos e
      espaço em disco
- [ ] Verificar o formato do QR Code do boletim impresso, para permitir a
      conferência de uma urna pelo celular

### 2. Coleta e armazenamento

- [ ] Comando para baixar os boletins de uma eleição, incremental e
      respeitando o limite do TSE (como a sincronização e as fotos)
- [ ] Modelo de dados por seção: zona, seção, município, votos por candidato,
      brancos, nulos, eleitores aptos e comparecimento
- [ ] Guardar o hash de cada arquivo baixado, para provar que o dado
      conferido é o mesmo publicado pelo TSE
- [ ] Verificar os hashes publicados pelo TSE (e, mais adiante, as
      assinaturas digitais dos boletins)
- [ ] Tarefa no painel admin para iniciar e acompanhar a coleta

### 3. Conferência

- [ ] Somar os votos por candidato das seções de cada município e comparar
      com o resultado municipal divulgado
- [ ] Repetir a conferência para cada estado e para o país
- [ ] Classificar cada diferença: seção agregada, seção sem boletim,
      candidato com votos anulados, retotalização, ou sem explicação
- [ ] Testes com dados simulados para cada tipo de diferença

### 4. Seção de transparência no site

- [ ] Página separada (por exemplo "Transparência" ou "Conferência das
      urnas"), fora do fluxo principal de resultados
- [ ] Explicação em linguagem simples do que é um boletim de urna e de como
      a conferência é feita
- [ ] Situação por eleição: quantas seções foram conferidas e se a soma bate
- [ ] Votos de cada urna (seção): quantidade por candidato, brancos, nulos,
      eleitores aptos e comparecimento, com o boletim de urna da seção
- [ ] Somas por zona eleitoral
- [ ] Somas por município
- [ ] Somas por estado
- [ ] Soma total do país
- [ ] Em cada nível, a soma das urnas lado a lado com o resultado oficial
      divulgado pelo TSE, indicando se bate e explicando qualquer diferença
- [ ] Navegação entre os níveis: país → estado → município → zona → urna,
      com busca por município, zona e número da seção
- [ ] Download dos dados conferidos e link para o código e a metodologia
- [ ] Revisão de texto com cuidado especial: neutralidade e sem margem para
      interpretações enganosas

## Outras pendências

- [ ] A aba "Conselheiro Distrital" aparece fora do DF (vem da lista de
      cargos do backend)
- [ ] Cartões de candidato apertados no celular, com nomes longos quebrando
      em duas linhas (empilhar os votos embaixo do nome em telas estreitas)
- [ ] Avaliar se as notificações de atualização de municípios (no máximo a
      cada 10 minutos) não são frequentes demais
