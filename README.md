# 📦 Gestão e Registo de Envios de Stock via Webhook

Módulo para **PrestaShop 1.7.6.2** destinado à gestão, controlo e registo dos envios de informação de stock para uma **Intranet**, utilizando **Webhooks HTTP**.

O módulo permite sincronizar produtos e respetivas quantidades de stock entre o PrestaShop e um sistema externo, mantendo um registo dos envios efetuados e do respetivo resultado.

---

## 🚀 Funcionalidades

* 🔄 Envio de informação de stock para a Intranet através de Webhook.
* 📦 Sincronização de produtos e respetivas quantidades.
* 📝 Registo dos envios efetuados.
* ✅ Registo de envios bem-sucedidos.
* ❌ Registo de erros e respostas inválidas.
* 🔁 Possibilidade de repetir/reprocessar envios.
* 🔐 Comunicação através de HTTP/HTTPS.
* 🔑 Autenticação através de token/secret do Webhook.
* 📊 Consulta do histórico de envios.
* 🕒 Registo de data e hora de cada operação.
* 📡 Integração independente da base de dados principal da Intranet.
* ⚡ Comunicação através de API/Webhook, evitando acesso direto à base de dados externa.

---

## 🎯 Objetivo

O objetivo deste módulo é permitir que o **PrestaShop funcione como origem dos dados de stock**, enviando a informação necessária para a Intranet de forma controlada e rastreável.

### Fluxo

```text
┌───────────────────┐
│    PrestaShop     │
│    1.7.6.2        │
└─────────┬─────────┘
          │
          │ Alteração / envio de stock
          ▼
┌───────────────────┐
│      Plugin       │
│     Webhook       │
└─────────┬─────────┘
          │
          │ HTTP/HTTPS
          ▼
┌───────────────────┐
│  API da Intranet  │
│      Webhook      │
└─────────┬─────────┘
          │
          ▼
┌───────────────────┐
│ Base de dados WINE│
└───────────────────┘
```

O módulo mantém ainda um **histórico local dos pedidos**, permitindo verificar o que foi enviado, quando foi enviado e qual foi a resposta recebida.

---

## 🧩 Compatibilidade

| Componente    | Versão                                            |
| ------------- | ------------------------------------------------- |
| PrestaShop    | `1.7.6.2`                                         |
| PHP           | Compatível com a versão suportada pelo PrestaShop |
| Comunicação   | HTTP / HTTPS                                      |
| API           | REST / Webhook                                    |
| Base de dados | MySQL / MariaDB                                   |

> ⚠️ O módulo foi desenvolvido tendo como referência o **PrestaShop 1.7.6.2**.

---

## 📥 Instalação

### 1. Download

Descarregue ou clone o repositório:

```bash
git clone https://github.com/SEU-UTILIZADOR/SEU-REPOSITORIO.git
```

Ou descarregue o projeto em formato ZIP através do GitHub.

### 2. Copiar o módulo

Copie a pasta do módulo para:

```text
/modules/
```

Exemplo:

```text
/modules/stockwebhook/
```

A estrutura deverá ficar semelhante a:

```text
modules/
└── stockwebhook/
    ├── stockwebhook.php
    ├── config.xml
    ├─
```
