<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\Conf;

/**
 * Yii2 Kafka Worker
 *
 * @package muxtorov98/yii2-kafka
 * @author  Tulqin Muxtorov <tulqin484@gmail.com>
 * @license MIT
 * @link    https://github.com/muxtorov98/yii2-kafka
 */
final class KafkaOptions
{
    public string $brokers;
    public array $security = [];
    public array $producer = [];
    public array $consumer = [];
    public array $retry = [];

    public static function fromArray(array $config): self
    {
        $self = new self();
        $self->brokers = $config['brokers'] ?? 'kafka:9092';
        $self->security = $config['security'] ?? [];
        $self->producer = $config['producer'] ?? [];
        $self->consumer = $config['consumer'] ?? [];
        $self->retry = $config['retry'] ?? [
            'max_attempts' => 3,
            'backoff_ms' => 500,
        ];
        return $self;
    }

    public function consumerConf(?string $groupId = null): Conf
    {
        $conf = new Conf();
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('group.id', $groupId ?? $this->consumer['group.id'] ?? 'yii2-group');

        $autoCommit = $this->consumer['auto_commit'] ?? true;
        $offsetReset = $this->consumer['auto_offset_reset'] ?? 'earliest';

        $conf->set('enable.auto.commit', $autoCommit ? 'true' : 'false');
        $conf->set('auto.offset.reset', $offsetReset);

        $conf->set('max.poll.interval.ms', (string)($this->consumer['max_poll_interval_ms'] ?? 300000));
        $this->applySecurity($conf);
        return $conf;
    }

    public function producerConf(): Conf
    {
        $conf = new Conf();
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('acks', $this->producer['acks'] ?? 'all');
        $conf->set('compression.type', $this->producer['compression'] ?? 'lz4');
        $conf->set('linger.ms', (string)($this->producer['linger_ms'] ?? 1));
        $this->applySecurity($conf);
        return $conf;
    }

    private function applySecurity(Conf $conf): void
    {
        if (!empty($this->security['protocol'])) {
            $conf->set('security.protocol', $this->security['protocol']);
        }

        if (!empty($this->security['sasl'])) {
            $conf->set('sasl.mechanisms', $this->security['sasl']['mechanism'] ?? 'PLAIN');
            $conf->set('sasl.username', $this->security['sasl']['username'] ?? '');
            $conf->set('sasl.password', $this->security['sasl']['password'] ?? '');
        }

        if (!empty($this->security['ssl']['ca'])) {
            $conf->set('ssl.ca.location', $this->security['ssl']['ca']);
        }
    }

    public function retryMaxAttempts(): int
    {
        return max(1, (int) ($this->retry['max_attempts'] ?? 3));
    }

    public function retryBackoffMs(): int
    {
        return max(0, (int) ($this->retry['backoff_ms'] ?? 500));
    }

    public function commitOnFailure(): bool
    {
        return (bool) ($this->consumer['commit_on_failure'] ?? false);
    }

    public function consumeTimeoutMs(): int
    {
        return max(100, (int) ($this->consumer['consume_timeout_ms'] ?? 1000));
    }

    public function producerFlushTimeoutMs(): int
    {
        return max(100, (int) ($this->producer['flush_timeout_ms'] ?? 1000));
    }

    public function producerFlushRetries(): int
    {
        return max(1, (int) ($this->producer['flush_retries'] ?? 3));
    }
}
