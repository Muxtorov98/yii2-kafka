<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\Conf;

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

    public function consumerConf(): Conf
    {
        $conf = new Conf();
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('group.id', $this->consumer['group.id'] ?? 'yii2-group');

        // ✅ Default values qo‘shildi
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
}
