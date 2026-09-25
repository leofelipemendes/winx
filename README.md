# Teste técnico — API de produtos

API desenvolvida com Laravel para cadastrar, listar, atualizar, excluir e pesquisar produtos. Inclui autenticação, busca com Elasticsearch e registro das alterações feitas nos produtos.

## Como rodar localmente

### 1. Pré-requisitos

Tenha o Docker e o Docker Compose instalados e em execução. Os comandos abaixo devem ser executados no terminal, dentro da pasta do projeto.

O Docker prepara PHP 8.4, Composer, Nginx, PostgreSQL 16 e Elasticsearch 9.1. Não é necessário instalar PHP, Composer ou banco de dados na sua máquina.

As portas `80`, `5432` e `9200` devem estar livres. A primeira inicialização pode levar alguns minutos para baixar as imagens.

### 2. Configure o ambiente

Na primeira execução, copie o arquivo de exemplo:

```bash
cp .env.example .env
```

Se já tiver um `.env` configurado, edite o arquivo existente em vez de substituí-lo.

O arquivo de exemplo usa SQLite. Para utilizar o PostgreSQL do Docker, ajuste as seguintes linhas no `.env`. Remova o `#` das linhas de banco que estiverem comentadas:

```dotenv
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret

QUEUE_CONNECTION=database
CACHE_STORE=database

ELASTICSEARCH_HOSTS=http://elasticsearch:9200
ELASTICSEARCH_INDEX=products_v1
ELASTICSEARCH_QUEUE=search
ELASTICSEARCH_QUEUE_CONNECTION=database
```

Os nomes `db` e `elasticsearch` são os serviços definidos no Docker Compose. As credenciais acima são apenas para o ambiente local.

### 3. Inicie os serviços

```bash
docker compose up -d --build
```

Para conferir se os serviços estão em execução:

```bash
docker compose ps
```

### 4. Instale as dependências e prepare o banco

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Esses comandos instalam as dependências, geram a chave da aplicação e criam as tabelas. Gere a chave apenas na configuração inicial.

Para começar com 20 produtos de exemplo e um usuário de teste, execute uma vez:

```bash
docker compose exec app php artisan db:seed
```

O usuário criado tem e-mail `test@example.com` e senha `password`.

### 5. Prepare a busca de produtos

Crie o índice, onde o Elasticsearch organiza os dados para pesquisa, e importe os produtos do banco:

```bash
docker compose exec app php artisan products:search-create 
docker compose exec app php artisan products:search-import 
```

Faça a importação depois de inserir os dados de exemplo. O PostgreSQL continua sendo o banco principal da aplicação.

### 6. Inicie o processamento em segundo plano

Abra dois terminais na pasta do projeto e deixe os comandos abaixo em execução enquanto utiliza a API.

No primeiro terminal, processe os registros de alterações dos produtos:

```bash
docker compose exec app php artisan queue:work database --queue=default --tries=3 --timeout=30
```

No segundo terminal, processe as atualizações da busca:

```bash
docker compose exec app php artisan queue:work database --queue=search --tries=5 --timeout=30
```

Sem esses processos, as tarefas ficam aguardando na fila. Após criar, editar ou excluir um produto, a busca pode levar alguns segundos para refletir a alteração.

### 7. Acesse e experimente a API

A aplicação fica disponível em **http://localhost**, e os endpoints da API começam com **http://localhost/api**. Não há uma interface visual para gerenciar produtos; utilize o Postman.

1. Importe o arquivo [test-winx.postman_collection.json](test-winx.postman_collection.json) no Postman.
2. Nas variáveis da coleção, mantenha `base_url` como `http://localhost`.
3. Preencha `email` com `test@example.com` e `password` com `password`, caso tenha executado o comando de dados de exemplo.
4. Execute a requisição **Login**. A coleção salva automaticamente o token usado nas requisições protegidas.
5. Use as requisições de produtos para cadastrar, consultar, editar e excluir registros.

Se não tiver carregado os dados de exemplo, preencha também a variável `name`, execute **Cadastrar usuário** e depois **Login**.

Para experimentar a busca, use uma requisição GET com o mesmo token:

```text
http://localhost/api/products?q=teclado
http://localhost/api/products/suggestions?q=tec
```

O parâmetro `q` pesquisa no nome e na descrição. A rota `suggestions` retorna nomes de produtos a partir do texto digitado. Para testar esses exemplos, cadastre um produto com o nome “Teclado” e aguarde o processamento da fila.

É possível combinar a busca com filtros de categoria, preço, estoque e paginação:

```text
http://localhost/api/products?q=teclado&min_price=50&in_stock=true&per_page=10&page=1
```

### 8. Execute os testes

Para executar os testes PHPUnit sem depender do Elasticsearch:

```bash
docker compose exec app php artisan test --compact --exclude-group=elasticsearch
```

Para executar todos os testes, incluindo a integração com Elasticsearch, mantenha o serviço iniciado e execute:

```bash
docker compose exec -e RUN_ELASTICSEARCH_TESTS=1 app vendor/bin/phpunit
```

Os testes usam um banco separado em memória e índices exclusivos de teste, preservando os dados locais da aplicação.

### 9. Encerre o ambiente

Interrompa os dois processos de fila com `Ctrl+C` e pare os serviços:

```bash
docker compose down
```

Os dados do banco e do Elasticsearch permanecem nos volumes do Docker. Para voltar a utilizar o projeto, execute `docker compose up -d` e inicie novamente os dois processos de fila.

## Diferenciais implementados

- **Autenticação com Sanctum:** acesso aos produtos protegido por token, com cadastro, login, logout e recuperação de senha.
- **Reposytory Layer:** uma camada que centraliza o acesso aos dados, como buscar, criar, atualizar e excluir produtos.
- **Busca com Elasticsearch:** pesquisa por nome e descrição, prioridade para correspondências no nome e sugestões durante a digitação.
- **Filtros e paginação:** consulta por categoria, faixa de preço e disponibilidade em estoque.
- **Auditoria em segundo plano:** registro de criação, atualização e exclusão, incluindo usuário, data e valores alterados. Os registros ficam em `storage/logs/laravel.log` e na tabela `product_activity_events`.
- **Filas separadas:** a atualização da busca e a auditoria são processadas de forma independente, após a confirmação da operação no banco.
- **Testes automatizados:** cobertura de autenticação, produtos, busca, auditoria e processamento das filas com PHPUnit.
