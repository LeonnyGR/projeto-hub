-- Roda UMA vez (volume vazio), como superuser, no banco POSTGRES_DB.
-- Simula o Supabase: roles anon/authenticated/service_role sem privilegios, RLS automatico em toda
-- tabela nova do schema public e uma role de aplicacao com privilegios minimos.
--
-- Nomes e senhas vem do ambiente do container (psql \getenv); nada de segredo neste arquivo:
--   POSTGRES_DB, DB_USERNAME, DB_PASSWORD, DB_MIGRATION_USERNAME, DB_MIGRATION_PASSWORD, CRIAR_BANCO_TESTE
\set ON_ERROR_STOP on

\getenv db POSTGRES_DB
\getenv app_user DB_USERNAME
\getenv app_pw DB_PASSWORD
\getenv mig_user DB_MIGRATION_USERNAME
\getenv mig_pw DB_MIGRATION_PASSWORD

-- ---------------------------------------------------------------------------------------------
-- 1) Roles estilo Supabase: existem, mas nao tem NENHUM privilegio nas tabelas
-- ---------------------------------------------------------------------------------------------
CREATE ROLE anon NOLOGIN NOINHERIT NOBYPASSRLS;
CREATE ROLE authenticated NOLOGIN NOINHERIT NOBYPASSRLS;
CREATE ROLE service_role NOLOGIN NOINHERIT NOBYPASSRLS;

-- ---------------------------------------------------------------------------------------------
-- 2) Roles da plataforma (sem superuser)
--    * migrator: dono do schema; roda as migrations (DDL). Usada so por docker/scripts/migrate.sh.
--    * app     : runtime da API/worker; so DML. BYPASSRLS porque aqui ela faz o papel do "service_role"
--                do Supabase: o RLS fecha as portas anon/authenticated, nao a aplicacao. O isolamento
--                entre clientes e feito na aplicacao (toda consulta parte do cliente logado + testes).
-- ---------------------------------------------------------------------------------------------
CREATE ROLE :"mig_user" LOGIN PASSWORD :'mig_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE :"app_user" LOGIN PASSWORD :'app_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION BYPASSRLS;

-- ---------------------------------------------------------------------------------------------
-- 3) Privilegios: tudo fechado por padrao
-- ---------------------------------------------------------------------------------------------
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO :"mig_user", :"app_user";

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO :"mig_user";
GRANT USAGE ON SCHEMA public TO :"app_user";

-- Tabelas/sequences criadas pelo migrator ficam acessiveis (so DML) para a role da aplicacao.
ALTER DEFAULT PRIVILEGES FOR ROLE :"mig_user" IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_user";
ALTER DEFAULT PRIVILEGES FOR ROLE :"mig_user" IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO :"app_user";

-- anon/authenticated/service_role: nenhum GRANT (nem CONNECT, nem USAGE no schema).

-- ---------------------------------------------------------------------------------------------
-- 4) Event trigger: habilita RLS automaticamente em todo CREATE TABLE do schema public
--    (mesmo comportamento do "automatic RLS" do Supabase). Sem policies = ninguem alem de
--    superuser/dono/BYPASSRLS le ou escreve.
-- ---------------------------------------------------------------------------------------------
CREATE SCHEMA infra;
REVOKE ALL ON SCHEMA infra FROM PUBLIC;

CREATE FUNCTION infra.rls_auto_enable() RETURNS event_trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $fn$
DECLARE
    cmd record;
BEGIN
    FOR cmd IN
        SELECT *
          FROM pg_event_trigger_ddl_commands()
         WHERE command_tag IN ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')
           AND object_type IN ('table', 'partitioned table')
    LOOP
        IF cmd.schema_name = 'public' AND NOT cmd.in_extension THEN
            EXECUTE format('ALTER TABLE IF EXISTS %s ENABLE ROW LEVEL SECURITY', cmd.object_identity);
        END IF;
    END LOOP;
END;
$fn$;

CREATE EVENT TRIGGER rls_auto_enable
    ON ddl_command_end
    WHEN TAG IN ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')
    EXECUTE FUNCTION infra.rls_auto_enable();

-- ---------------------------------------------------------------------------------------------
-- 5) Opcional (dev/CI): banco frete_test clonado deste (herda event trigger e privilegios).
--    So se CRIAR_BANCO_TESTE=1. Nao habilite em producao.
-- ---------------------------------------------------------------------------------------------
\getenv criar CRIAR_BANCO_TESTE
\if :{?criar}
    SELECT (:'criar' = '1') AS criar_teste \gset
\else
    \set criar_teste false
\endif

\if :criar_teste
    \connect postgres
    CREATE DATABASE frete_test TEMPLATE :"db" OWNER :"mig_user";
\endif
