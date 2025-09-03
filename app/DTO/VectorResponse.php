<?php namespace App\DTO;

class VectorResponse implements Base
{
    /**
     * @param string $answer
     * @param string $image
     */
    private function __construct(
        public string $answer,
        public string $image,
    ) {
    }

    /**
     * @param array<string, string|int> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self($data['answer'], $data['image']);
    }
}
