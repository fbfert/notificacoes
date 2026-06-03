/**
 * Exemplo de uso em backend. Nunca envie a API key para navegador ou frontend publico.
 * Requer Node.js 18+ com fetch global.
 */

async function main() {
  const baseUrl = process.env.TARS_NOTIFICACOES_BASE_URL || 'https://gateway.tars.art.br';
  const apiKey = process.env.TARS_NOTIFICACOES_API_KEY || '';
  const testPhone = process.env.TARS_NOTIFICACOES_TEST_PHONE || '5549999999999';

  if (!apiKey) {
    throw new Error('TARS_NOTIFICACOES_API_KEY nao informado.');
  }

  const idempotencyKey = `kit-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
  const response = await fetch(`${baseUrl.replace(/\/$/, '')}/api/sms/send`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${apiKey}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({
      phone: testPhone,
      message: 'Teste de integracao do projeto cliente com o Tars Notificacoes.',
      type: 'test',
      idempotency_key: idempotencyKey,
    }),
  });

  const text = await response.text();
  let payload = text;
  try {
    payload = JSON.parse(text);
  } catch (_) {
    // Mantem o texto cru se nao houver JSON.
  }

  console.log(`HTTP: ${response.status}`);
  console.log(JSON.stringify(payload, null, 2));

  if ([200, 202].includes(response.status)) {
    return;
  }

  if ([401, 415, 422, 500].includes(response.status)) {
    return;
  }

  throw new Error(`Status inesperado: ${response.status}`);
}

main().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
