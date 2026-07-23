<?php

/**
 * RedisCache - Wrapper para operações de cache com Redis
 * PHP 8.4+ | Redis 7.2+
 *
 * Fornece uma interface simplificada para operações de cache
 * com suporte a serialização automática, TTL e namespaces
 *
 * @package Infinnity Importacao
 * @version 1.0.0
 */

class RedisCache
{
    private static ?self $instance = null;

    private ?object $redis = null;

    private string $prefix = '';

    private bool $connected = false;

    private bool $enabled = true;

    /**
     * Construtor privado para implementar Singleton
     *
     * @param string $host Host do Redis
     * @param int $port Porta do Redis
     * @param string $password Senha do Redis
     * @param string $prefix Prefixo para chaves (namespace)
     * @param int $database Número do database (0-15)
     */
    private function __construct(
        string $host = 'redis',
        int $port = 6379,
        string $password = '',
        string $prefix = 'infinnityimportacao:',
        int $database = 0
    ) {
        if (!extension_loaded('redis')) {
            error_log('RedisCache: Extensão Redis não está carregada. Cache desabilitado.');
            $this->enabled = false;
            return;
        }

        try {
            $redisClass = 'Redis';
            $this->redis = new $redisClass();
            $this->prefix = $prefix;

            // Conectar ao Redis
            $connected = $this->redis->connect($host, $port, 2.5);

            if (!$connected) {
                throw new Exception('Falha ao conectar ao Redis');
            }

            // Autenticar se senha foi fornecida
            if (!empty($password)) {
                $this->redis->auth($password);
            }

            // Selecionar database
            $this->redis->select($database);

            // Configurar opções
            $this->redis->setOption(1, 1);      // OPT_SERIALIZER=1, SERIALIZER_PHP=1
            $this->redis->setOption(2, $this->prefix);  // OPT_PREFIX=2

            $this->connected = true;
        } catch (Exception $e) {
            error_log('RedisCache Error: ' . $e->getMessage());
            $this->enabled = false;
            $this->connected = false;
        }
    }

    /**
     * Retorna instância única do RedisCache (Singleton)
     *
     * @param array $config Configurações opcionais
     * @return RedisCache|null
     */
    public static function getInstance(array $config = []): ?RedisCache
    {
        if (self::$instance === null) {
            $host = $config['host'] ?? (defined('REDIS_HOST') ? constant('REDIS_HOST') : 'redis');
            $port = $config['port'] ?? (defined('REDIS_PORT') ? constant('REDIS_PORT') : 6379);
            $password = $config['password'] ?? (defined('REDIS_PASSWORD') ? constant('REDIS_PASSWORD') : '');
            $prefix = $config['prefix'] ?? (defined('REDIS_PREFIX') ? constant('REDIS_PREFIX') : 'infinnityimportacao:');
            $database = $config['database'] ?? (defined('REDIS_DATABASE') ? constant('REDIS_DATABASE') : 0);

            self::$instance = new self($host, $port, $password, $prefix, $database);
        }

        return self::$instance;
    }

    /**
     * Verifica se o Redis está conectado e habilitado
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->enabled;
    }

    /**
     * Armazena um valor no cache
     *
     * @param string $key Chave do cache
     * @param mixed $value Valor a ser armazenado (será serializado automaticamente)
     * @param int $ttl Tempo de vida em segundos (0 = sem expiração)
     * @return bool
     */
    public function set(string $key, mixed $value, int $ttl = 60): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            if ($ttl > 0) {
                return $this->redis->setex($key, $ttl, $value);
            } else {
                return $this->redis->set($key, $value);
            }
        } catch (Exception $e) {
            error_log('RedisCache::set Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera um valor do cache
     *
     * @param string $key Chave do cache
     * @param mixed $default Valor padrão se a chave não existir
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isConnected()) {
            return $default;
        }

        try {
            $value = $this->redis->get($key);
            return ($value === false) ? $default : $value;
        } catch (Exception $e) {
            error_log('RedisCache::get Error: ' . $e->getMessage());
            return $default;
        }
    }

    /**
     * Verifica se uma chave existe no cache
     *
     * @param string $key Chave do cache
     * @return bool
     */
    public function has(string $key): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->exists($key) > 0;
        } catch (Exception $e) {
            error_log('RedisCache::has Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove uma ou mais chaves do cache
     *
     * @param string|array $keys Chave(s) a serem removidas
     * @return int Número de chaves removidas
     */
    public function delete(string|array $keys): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        try {
            return $this->redis->del($keys);
        } catch (Exception $e) {
            error_log('RedisCache::delete Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove todas as chaves que correspondem a um padrão
     *
     * @param string $pattern Padrão (ex: 'user:*', 'session:*')
     * @return int Número de chaves removidas
     */
    public function deletePattern(string $pattern): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        try {
            // Quando OPT_PREFIX está ativo, precisamos usar o padrão sem prefixo
            // pois o Redis adiciona automaticamente
            $keys = $this->redis->keys($pattern);

            if (empty($keys)) {
                return 0;
            }

            // Remover o prefixo das chaves retornadas para usar com del()
            // pois del() também adiciona o prefixo automaticamente
            $keysWithoutPrefix = array_map(function ($key) {
                return str_replace($this->prefix, '', $key);
            }, $keys);

            $deleted = $this->redis->del($keysWithoutPrefix);

            return $deleted;
        } catch (Exception $e) {
            error_log('RedisCache::deletePattern Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Limpa todo o cache do database atual
     *
     * @return bool
     */
    public function flush(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->flushDB();
        } catch (Exception $e) {
            error_log('RedisCache::flush Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Incrementa um valor numérico
     *
     * @param string $key Chave do cache
     * @param int $value Valor a incrementar (padrão: 1)
     * @return int|false Novo valor ou false em caso de erro
     */
    public function increment(string $key, int $value = 1): int|false
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->incrBy($key, $value);
        } catch (Exception $e) {
            error_log('RedisCache::increment Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * SET key value NX EX ttl numa unica chamada atomica — grava so se a chave
     * ainda nao existir, ja com TTL. Usado por check_and_increment_rate_limit()
     * pra criar o contador inicial sem a janela de crash de um incr()+expire()
     * em duas chamadas separadas (se o processo morresse entre as duas, a chave
     * ficava sem TTL e o rate limit bloqueava aquela chave pra sempre).
     *
     * So aceita valor NUMERICO (contador de rate limit, nao cache serializado):
     * usa rawCommand() de proposito, que ignora OPT_SERIALIZER — o set() normal
     * serializa ate inteiros (formato PHP, ex.: "i:1;"), e o Redis nativo recusa
     * incrBy() nesse valor (retorna false em vez de incrementar). increment()
     * (que usa incrBy() puro) precisa achar um inteiro cru na chave.
     *
     * rawCommand() TAMBEM ignora OPT_PREFIX (confirmado empiricamente) — por
     * isso prefixamos $key manualmente com $this->prefix, senao a chave gravada
     * aqui fica fisicamente diferente da que increment()/expire() (metodos
     * normais, com prefixo automatico) tentam ler depois.
     *
     * @param string $key Chave do cache
     * @param int $value Valor numerico inicial (sem serializacao)
     * @param int $ttl Tempo de vida em segundos
     * @return bool true se a chave foi criada agora, false se ja existia (ou erro)
     */
    public function setIfNotExists(string $key, int $value, int $ttl): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return (bool)$this->redis->rawCommand(
                'SET',
                $this->prefix . $key,
                (string)$value,
                'NX',
                'EX',
                (string)$ttl
            );
        } catch (Exception $e) {
            error_log('RedisCache::setIfNotExists Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrementa um valor numérico
     *
     * @param string $key Chave do cache
     * @param int $value Valor a decrementar (padrão: 1)
     * @return int|false Novo valor ou false em caso de erro
     */
    public function decrement(string $key, int $value = 1): int|false
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->decrBy($key, $value);
        } catch (Exception $e) {
            error_log('RedisCache::decrement Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Define tempo de expiração para uma chave existente
     *
     * @param string $key Chave do cache
     * @param int $ttl Tempo de vida em segundos
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->expire($key, $ttl);
        } catch (Exception $e) {
            error_log('RedisCache::expire Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retorna o tempo de vida restante de uma chave
     *
     * @param string $key Chave do cache
     * @return int Segundos restantes (-1 = sem expiração, -2 = chave não existe)
     */
    public function ttl(string $key): int
    {
        if (!$this->isConnected()) {
            return -2;
        }

        try {
            return $this->redis->ttl($key);
        } catch (Exception $e) {
            error_log('RedisCache::ttl Error: ' . $e->getMessage());
            return -2;
        }
    }

    /**
     * Armazena múltiplos valores de uma vez
     *
     * @param array $data Array associativo [chave => valor]
     * @param int $ttl Tempo de vida em segundos (aplicado a todas as chaves)
     * @return bool
     */
    public function setMultiple(array $data, int $ttl = 60): bool
    {
        if (!$this->isConnected() || empty($data)) {
            return false;
        }

        try {
            $this->redis->multi();

            foreach ($data as $key => $value) {
                if ($ttl > 0) {
                    $this->redis->setex($key, $ttl, $value);
                } else {
                    $this->redis->set($key, $value);
                }
            }

            $results = $this->redis->exec();
            return !in_array(false, $results, true);
        } catch (Exception $e) {
            error_log('RedisCache::setMultiple Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera múltiplos valores de uma vez
     *
     * @param array $keys Array de chaves
     * @return array Array associativo [chave => valor]
     */
    public function getMultiple(array $keys): array
    {
        if (!$this->isConnected() || empty($keys)) {
            return [];
        }

        try {
            $values = $this->redis->mGet($keys);
            return array_combine($keys, $values);
        } catch (Exception $e) {
            error_log('RedisCache::getMultiple Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cache com callback - busca no cache ou executa função
     *
     * @param string $key Chave do cache
     * @param callable $callback Função a executar se não estiver em cache
     * @param int $ttl Tempo de vida em segundos
     * @return mixed
     */
    public function remember(string $key, callable $callback, int $ttl = 60): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Retorna informações sobre o servidor Redis
     *
     * @return array|false
     */
    public function info(): array|false
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->info();
        } catch (Exception $e) {
            error_log('RedisCache::info Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Lista todas as chaves que correspondem a um padrão
     *
     * @param string $pattern Padrão (ex: 'user:*', 'session:*')
     * @return array Lista de chaves (sem prefixo)
     */
    public function keys(string $pattern = '*'): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        try {
            $keys = $this->redis->keys($pattern);

            // Remover prefixo das chaves retornadas
            return array_map(function ($key) {
                return str_replace($this->prefix, '', $key);
            }, $keys);
        } catch (Exception $e) {
            error_log('RedisCache::keys Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Retorna o número de chaves que correspondem a um padrão
     *
     * @param string $pattern Padrão (ex: 'user:*', 'session:*')
     * @return int Número de chaves
     */
    public function countKeys(string $pattern = '*'): int
    {
        return count($this->keys($pattern));
    }

    /**
     * Fecha a conexão com o Redis
     */
    public function close(): void
    {
        if ($this->connected && $this->redis) {
            try {
                $this->redis->close();
                $this->connected = false;
            } catch (Exception $e) {
                error_log('RedisCache::close Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Previne clonagem do objeto Singleton
     */
    private function __clone() {}

    /**
     * Previne deserialização do objeto Singleton
     */
    public function __wakeup(): void
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
