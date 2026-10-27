# Webhook iFood para Locaweb

Envie `webhook.php`, `.htaccess`, a pasta `storage` e `config.example.php` para a pasta HTTPS do endpoint configurado no Portal iFood. Renomeie `config.example.php` para `config.php` no servidor e insira o Client Secret correto. Nunca publique o arquivo real de configuração em repositório ou anexo.

O endpoint recebe um evento por POST, valida `X-IFood-Signature` sobre os bytes originais, persiste o evento antes de responder HTTP 200 e rejeita assinatura inválida com HTTP 401. O diretório `storage` deve permitir gravação pelo PHP; o `.htaccess` bloqueia acesso HTTP direto aos arquivos. Ações `?action=pull` e `?action=ack` são protegidas por Bearer com o mesmo Client Secret e atendem ao cliente de fila do PDV.

O limite de fila é 5.000 eventos. Se não houver espaço, o endpoint responde 503 para o iFood repetir a entrega. A resposta 200 é usada para atender ao checklist específico de homologação Logistics; ela só ocorre após persistência bem-sucedida.
