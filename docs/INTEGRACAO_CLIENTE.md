# Integracao do Cliente

Este documento descreve como um sistema externo deve consumir o Tars Notificacoes nesta fase.

## Endpoint

- `POST /api/sms/send`

## Headers obrigatorios

- `Authorization: Bearer API_KEY`
- `Content-Type: application/json`

## Corpo da requisicao

```json
{
  "phone": "5549999999999",
  "message": "Teste Tars Notificacoes",
  "type": "test",
  "idempotency_key": "pedido-123"
}
```

## Exemplo em PHP com cURL

```php
<?php

$payload = json_encode([
    'phone' => '5549999999999',
    'message' => 'Teste Tars Notificacoes',
    'type' => 'test',
    'idempotency_key' => 'pedido-123',
]);

$ch = curl_init('https://gateway.tars.art.br/api/sms/send');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer SUA_API_KEY',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $payload,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

echo $httpCode . PHP_EOL;
echo $response . PHP_EOL;
```

## Exemplo em JavaScript com fetch

```javascript
const response = await fetch('https://gateway.tars.art.br/api/sms/send', {
  method: 'POST',
  headers: {
    Authorization: 'Bearer SUA_API_KEY',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    phone: '5549999999999',
    message: 'Teste Tars Notificacoes',
    type: 'test',
    idempotency_key: 'pedido-123',
  }),
});

const data = await response.json();
console.log(response.status, data);
```

## Respostas esperadas

- `202` envio aceito
- `200` requisicao idempotente
- `401` API key ausente ou invalida
- `415` Content-Type invalido
- `422` validacao

Tipos aceitos no MVP:

- `transactional`
- `alert`
- `test`

Para a acao administrativa de teste, use `type=test`.

## Observacao de homologacao

Nesta fase tudo continua em mock/log. Nenhum SMS real deve ser enviado.
