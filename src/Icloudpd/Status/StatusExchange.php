<?php

namespace Icloudpd\Status;

use Icloudpd\Config\GlobalConfig;
use Icloudpd\Config\UserConfig;
use Icloudpd\Progress\Progress;

// ponytail: no lock (the port is single-threaded, see PORTING.md): upstream's `with self.lock:` are dropped
class StatusExchange
{
    private Status $_status = Status::NO_INPUT_NEEDED;
    private ?string $_payload = null;
    private ?string $_error = null;
    private ?GlobalConfig $_global_config = null;
    /** @var list<UserConfig> */
    private array $_user_configs = [];
    private ?string $_current_user = null;
    private Progress $_progress;

    public function __construct()
    {
        $this->_progress = new Progress();
    }

    public function get_status(): Status
    {
        return $this->_status;
    }

    public function replace_status(Status $expected_status, Status $new_status): bool
    {
        if ($this->_status == $expected_status) {
            $this->_status = $new_status;
            return true;
        } else {
            return false;
        }
    }

    public function set_payload(string $payload): bool
    {
        if ($this->_status != Status::NEED_MFA && $this->_status != Status::NEED_PASSWORD) {
            return false;
        }

        $this->_payload = $payload;
        $this->_status = $this->_status == Status::NEED_MFA ? Status::SUPPLIED_MFA : Status::SUPPLIED_PASSWORD;
        $this->_error = null;
        return true;
    }

    public function get_payload(): ?string
    {
        if (!in_array($this->_status, [
            Status::SUPPLIED_MFA,
            Status::CHECKING_MFA,
            Status::SUPPLIED_PASSWORD,
            Status::CHECKING_PASSWORD,
        ], true)) {
            return null;
        }

        return $this->_payload;
    }

    public function set_error(string $error): bool
    {
        if ($this->_status != Status::CHECKING_MFA && $this->_status != Status::CHECKING_PASSWORD) {
            return false;
        }

        $this->_error = $error;
        $this->_status = $this->_status == Status::CHECKING_PASSWORD ? Status::NO_INPUT_NEEDED : Status::NEED_MFA;
        return true;
    }

    public function get_error(): ?string
    {
        if (!in_array($this->_status, [
            Status::NO_INPUT_NEEDED,
            Status::NEED_PASSWORD,
            Status::NEED_MFA,
        ], true)) {
            return null;
        }

        return $this->_error;
    }

    public function get_progress(): Progress
    {
        return $this->_progress;
    }

    public function set_global_config(GlobalConfig $global_config): void
    {
        $this->_global_config = $global_config;
    }

    public function get_global_config(): ?GlobalConfig
    {
        return $this->_global_config;
    }

    /** @param list<UserConfig> $user_configs */
    public function set_user_configs(array $user_configs): void
    {
        $this->_user_configs = $user_configs;
    }

    /** @return list<UserConfig> */
    public function get_user_configs(): array
    {
        return $this->_user_configs;
    }

    public function set_current_user(string $username): void
    {
        $this->_current_user = $username;
    }

    public function get_current_user(): ?string
    {
        return $this->_current_user;
    }

    public function clear_current_user(): void
    {
        $this->_current_user = null;
    }
}
