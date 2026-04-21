# ☁️ TCloud — Gerenciador de Arquivos Corporativo

Sistema web completo de gerenciamento de arquivos, com visual moderno, dark/light theme, editor de código integrado, sistema de permissões, painel administrativo, e muito mais.

## Requisitos

- **PHP 8.0+** com extensões: PDO, pdo_mysql, mbstring, fileinfo, gd, zip, json
- **MySQL 5.7+** ou MariaDB 10.3+
- **Apache** com mod_rewrite habilitado (ou Nginx com configuração equivalente)
- Navegador moderno (Chrome, Firefox, Edge, Safari)

## Instalação Rápida

### 1. Clone/copie o projeto para o diretório do servidor web

```bash
# Exemplo com Apache
cp -r tcloud/ /var/www/html/tcloud/
```

### 2. Configure o banco de dados

Edite `config/app.php` com suas credenciais:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'tcloud');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');
```

### 3. Execute a instalação

**Via navegador:** Acesse `http://seu-servidor/tcloud/install.php`

**Via terminal:**
```bash
cd /var/www/html/tcloud
php install.php
```

### 4. Acesse o sistema

- URL: `http://seu-servidor/tcloud/`
- Usuário: `admin`
- Senha: `Admin@123`

### 5. Segurança pós-instalação

- **Exclua `install.php`** após a instalação
- Altere a senha do admin imediatamente
- Certifique-se que `/storage/` não está acessível diretamente
- Configure HTTPS em produção

## Funcionalidades

### Gerenciamento de Arquivos
- Navegação por diretórios com breadcrumbs
- Upload por arrastar e soltar (drag & drop)
- Upload múltiplo com barra de progresso
- Criar, renomear, mover, copiar, excluir
- Seleção múltipla e operações em lote
- Lixeira com restauração
- Favoritos e arquivos recentes
- Pesquisa por nome
- Ordenação por nome, tamanho, data, tipo
- Visualização em lista e grade
- Menu de contexto (clique direito)
- Compactar/descompactar ZIP

### Visualização de Arquivos
- Imagens (jpg, png, gif, webp, svg)
- PDF (via iframe)
- Vídeos (mp4, webm)
- Áudios (mp3, wav, ogg)
- Texto e código (syntax highlighting)

### Editor de Código Integrado
- Editor Monaco (VS Code) integrado
- Suporte a: HTML, CSS, JS, JSON, XML, Markdown, CSV, TXT, etc.
- Destaque de sintaxe, numeração de linhas
- Busca e substituição
- Ctrl+S para salvar
- "Salvar como" para novo arquivo
- Tema dark/light sincronizado

### Compartilhamento
- Links públicos com token
- Proteção por senha
- Expiração configurável
- Limite de downloads
- Permissões (visualizar, baixar, editar)

### Segurança
- Autenticação com hash bcrypt
- Proteção CSRF em todas as requisições
- Prevenção de XSS (sanitização de output)
- Queries parametrizadas (SQL Injection)
- Extensões perigosas bloqueadas
- Prevenção de execução de scripts enviados
- Bloqueio por tentativas de login
- Logs de auditoria completos

### Sistema de Permissões
- 3 papéis: Master, Administrador, Usuário
- Permissões granulares por papel e por usuário
- 17 permissões configuráveis

### Painel Administrativo
- Dashboard com métricas
- Gerenciamento de usuários (CRUD)
- Logs de auditoria
- Controle de quotas de armazenamento
- Ativar/desativar usuários

### Interface
- Tema dark e light com alternância
- Design premium e responsivo
- Animações suaves
- Skeleton loading
- Toast notifications
- Fontes DM Sans + JetBrains Mono

## Estrutura do Projeto

```
tcloud/
├── config/
│   └── app.php              # Configurações
├── core/
│   ├── Auth.php             # Autenticação e permissões
│   ├── AuditLog.php         # Sistema de auditoria
│   ├── Database.php         # Conexão PDO singleton
│   ├── FileManager.php      # Operações de arquivos
│   └── helpers.php          # Funções auxiliares
├── api/
│   ├── index.php            # API principal (AJAX)
│   └── download.php         # Download e preview
├── migrations/
│   └── 001_schema.sql       # Schema do banco
├── public/
│   └── css/
│       └── app.css          # Estilos completos
├── storage/
│   ├── files/               # Arquivos dos usuários
│   ├── trash/               # Lixeira física
│   └── temp/                # Arquivos temporários
├── bootstrap.php            # Autoload
├── index.php                # Aplicação principal
├── login.php                # Tela de login
├── share.php                # Acesso a links compartilhados
├── install.php              # Instalador
├── .htaccess                # Regras Apache
└── README.md
```

## Integração com Editores Office

O sistema está preparado para integração com:

- **OnlyOffice Document Server** — Configure a URL em `system_settings`
- **Collabora Online** — Adaptável via iframe
- Para ativação, configure `onlyoffice_url` e `onlyoffice_secret` nas configurações do sistema

## Tecnologias Utilizadas

- PHP 8+ (PDO, preparado para namespaces/PSR)
- MySQL/MariaDB
- JavaScript vanilla (sem frameworks, máximo desempenho)
- CSS3 custom properties (dark/light themes)
- Bootstrap Icons
- Monaco Editor (VS Code no navegador)
- DM Sans + JetBrains Mono (tipografia)

## Licença

Projeto desenvolvido como base corporativa. Use conforme necessário.
