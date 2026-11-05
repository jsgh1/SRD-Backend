<?php
namespace SrdFiles;

interface PhotoStorage
{
    public function list(array $principal, string $person): array;
    public function put(array $principal, string $person, string $slot, string $name, string $bytes, int $version): array;
    public function read(array $principal, string $person, string $slot): array;
    public function delete(array $principal, string $person, string $slot, int $version, bool $confirmed): array;
    public function purgePerson(array $principal, string $person): void;
}
