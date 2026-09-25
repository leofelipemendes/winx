# API Laravel com Docker e PostgreSQL

Ambiente de desenvolvimento containerizado para uma API Laravel, composto por:

| Serviço | Imagem              | Porta local |
|---------|---------------------|-------------|
| `app`   | PHP 8.3-FPM (build) | -           |
| `nginx` | nginx:alpine        | 8000        |
| `db`    | postgres:16-alpine  | 5432        |

## Pré-requisitos

- [Docker](https://docs.docker.com/get-docker/) 24 ou superior
- [Docker Compose](https://docs.docker.com/compose/) v2 (já incluso no Docker Desktop)
- Git

Verifique a instalação:

```bash
docker --version
docker compose version
```

## Estrutura de arquivos

```
.
├── Dockerfile
├── docker-compose.yml
├── .dockerignore
├── docker/
│   └── nginx/
│       └── default.conf
└── (código do Laravel)
```

## Setup

### 1. Obter o projeto

**Projeto Laravel já existente:** clone o repositório e copie os arquivos `Dockerfile`, `docker-compose.yml`, `.dockerignore` e a pasta `docker/` para a raiz.

```bash
git clone <url-do-repositorio> minha-api
cd minha-api
```

**Projeto novo:** crie um projeto Laravel na pasta atual (que deve conter apenas os arquivos Docker):

```bash
docker run --rm -v "$(pwd)":/app composer create-project laravel/laravel tmp
mv tmp/* tmp/.[!.]* . && rmdir tmp
```

### 2. Configurar o `.env`

```bash
cp .env.example .env
```

Edite o `.env` e ajuste a conexão com o banco:

```env
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret
```

> `DB_HOST` deve ser `db`, o nome do serviço no `docker-compose.yml`, e não `localhost`.

Os valores acima são os padrões do compose. Para alterá-los, defina as mesmas variáveis (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) no `.env`. O Compose as lê automaticamente.

### 3. Subir os containers

```bash
docker compose up -d --build
```

Confira se estão rodando:

```bash
docker compose ps
```

### 4. Instalar dependências e preparar a aplicação

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Opcionalmente, popule o banco:

```bash
docker compose exec app php artisan db:seed
```

### 5. Acessar

A API estará disponível em **http://localhost:8000**.

Teste rapidamente:

```bash
curl -i http://localhost:8000/up
```

## Comandos úteis

| Ação                         | Comando                                                   |
|------------------------------|-----------------------------------------------------------|
| Subir os containers          | `docker compose up -d`                                    |
| Parar os containers          | `docker compose down`                                     |
| Ver logs                     | `docker compose logs -f`                                  |
| Logs de um serviço           | `docker compose logs -f app`                              |
| Shell no container da app    | `docker compose exec app sh`                              |
| Rodar Artisan                | `docker compose exec app php artisan <comando>`           |
| Rodar testes                 | `docker compose exec app php artisan test`                |
| Acessar o PostgreSQL (psql)  | `docker compose exec db psql -U laravel -d laravel`       |
| Recriar imagem               | `docker compose build --no-cache`                         |
| Apagar tudo (incluindo dados)| `docker compose down -v`                                  |

## Acessando o banco por um cliente externo

Use DBeaver, TablePlus, DataGrip ou similar com:

- **Host:** `localhost`
- **Porta:** `5432`
- **Banco:** `laravel`
- **Usuário:** `laravel`
- **Senha:** `secret`

## Solução de problemas

**Erro de permissão em `storage/` ou `bootstrap/cache`**

O container usa o UID/GID do seu usuário, definidos no build. Se o seu UID não for 1000, informe-o:

```bash
export UID GID=$(id -g)
docker compose up -d --build
```

Se o problema persistir:

```bash
docker compose exec -u root app chown -R www-data:www-data storage bootstrap/cache
```

**`SQLSTATE[08006] connection refused`**

- Confirme que `DB_HOST=db` no `.env`.
- Verifique se o banco está saudável: `docker compose ps` (deve aparecer `healthy`).
- Após alterar o `.env`, limpe o cache: `docker compose exec app php artisan config:clear`.

**Porta 8000 ou 5432 já em uso**

Altere o mapeamento de portas no `docker-compose.yml`, por exemplo `"8080:80"` para o Nginx ou `"5433:5432"` para o PostgreSQL.

**Mudanças no `.env` não surtem efeito**

```bash
docker compose exec app php artisan config:clear
docker compose restart app
```

**Resetar o banco do zero**

```bash
docker compose down -v
docker compose up -d
docker compose exec app php artisan migrate --seed
```

## Persistência de dados

Os dados do PostgreSQL ficam no volume Docker `pgdata` e são mantidos entre reinicializações. Só são removidos com `docker compose down -v`.

## Produção

Esta configuração é voltada para **desenvolvimento**. Para produção:

- Use um Dockerfile dedicado que copie o código para a imagem (sem *bind mounts*).
- Rode `composer install --no-dev --optimize-autoloader`.
- Defina `APP_ENV=production` e `APP_DEBUG=false`.
- Gerencie senhas e chaves por *secrets* ou variáveis de ambiente do orquestrador, nunca no repositório.
- Não exponha a porta do PostgreSQL publicamente.
