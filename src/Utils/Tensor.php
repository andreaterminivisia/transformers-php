<?php

declare(strict_types=1);

namespace Codewithkyrian\Transformers\Utils;

use ArrayObject;
use Countable;
use EmptyIterator;
use Exception;
use Interop\Polite\Math\Matrix\Buffer;
use Interop\Polite\Math\Matrix\NDArray;
use InvalidArgumentException;
use IteratorAggregate;
use LogicException;
use OutOfRangeException;
use Rindow\Math\Matrix\Complex;
use Rindow\Math\Matrix\ComplexUtils;
use Rindow\Math\Matrix\Drivers\MatlibPHP\MatlibPhp;
use Rindow\Math\Matrix\Drivers\Service;
use Rindow\Math\Matrix\MatrixOperator;
use Rindow\Math\Matrix\Range;
use RuntimeException;
use Serializable;
use Traversable;

class Tensor implements NDArray, Countable, Serializable, IteratorAggregate
{
    use ComplexUtils;

    const RANGE_STYLE_DEFAULT = 0;
    const RANGE_STYLE_1 = 1;
    static public int $rangeStyle = self::RANGE_STYLE_DEFAULT;

    const SERIALIZE_NDARRAY_KEYWORD = 'NDArray:';
    static public int $unserializeWarning = 2;

    protected static MatrixOperator $mo;
    protected static Service $service;


    protected array $shape;
    protected int $offset;
    protected int $dtype;
    protected Buffer $buffer;

    protected bool $portableSerializeMode = false;

    public function __construct(
        mixed $array = null,
        int   $dtype = null,
        array $shape = null,
        int   $offset = null,
    )
    {
        if ($array === null && $dtype === null && $shape === null && $offset === null) {
            // Empty definition for Unserialize
            return;
        }

        $orgDtype = $dtype;
        if ($dtype === null) {
            $dtype = NDArray::float32;
        }

        if ($array === null && $shape !== null) {
            $this->assertShape($shape);
            $size = (int)array_product($shape);
            $this->buffer = self::newBuffer($size, $dtype);
            $this->offset = 0;
        } elseif ($this->isBuffer($array)) {
            if (!is_int($offset))
                throw new InvalidArgumentException("Must specify offset with the buffer");
            if ($shape === null)
                throw new InvalidArgumentException("Invalid dimension size");
            $this->buffer = $array;
            $this->offset = $offset;
            $size = (int)array_product($shape);
        } elseif (is_array($array) || $array instanceof ArrayObject) {
            $size = $this->countRecursive($array);
            $this->buffer = self::newBuffer($size, $dtype);
            $this->flattenArray($array, $this->buffer);
            $this->offset = 0;
            $shape ??= $this->generateShape($array);
        } elseif (is_numeric($array) || is_bool($array) || $this->isComplexObject($array)) {
            if (is_numeric($array)) {
                if ($orgDtype == null) {
                    $dtype = NDArray::float32;
                }
            } elseif (is_bool($array)) {
                if ($orgDtype == null) {
                    $dtype = NDArray::bool;
                } else {
                    if ($dtype != NDArray::bool) {
                        throw new InvalidArgumentException("unmatch dtype with bool value");
                    }
                }
            } elseif ($this->isComplexObject($array)) {
                if ($orgDtype == null) {
                    $dtype = NDArray::complex64;
                } else {
                    if (!$this->isComplex($dtype)) {
                        throw new InvalidArgumentException("unmatch dtype with complex value");
                    }
                }
            }
            $this->buffer = self::newBuffer(1, $dtype);
            $this->buffer[0] = $array;
            $this->offset = 0;
            $shape ??= [];
            $this->assertShape($shape);
            $size = (int)array_product($shape);
            if ($size != 1)
                throw new InvalidArgumentException("Invalid dimension size");
        } else {
            throw new InvalidArgumentException("Invalid type of array");
        }

        $this->assertShape($shape);
        $this->shape = $shape;

        if (count($this->buffer) - $this->offset < $size)
            throw new InvalidArgumentException("Invalid dimension size");

        $this->dtype = $dtype;
    }


    function countRecursive($array): int
    {
        $count = 0;

        foreach ($array as $child) {
            if (is_array($child) || $child instanceof ArrayObject) {
                $count += $this->countRecursive($child);
            } else {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Create a new buffer for the tensor.
     *
     * @param int $size The size of the buffer.
     * @param int|null $dtype The data type of the buffer.
     */
    public static function newBuffer(int $size, ?int $dtype = null): Buffer
    {
        return self::service()->buffer()->Buffer($size, $dtype);
    }

    /**
     * Check if the given value is a buffer.
     */
    protected function isBuffer(mixed $buffer): bool
    {
        return $buffer instanceof Buffer;
    }

    protected function isComplex(int $dtype = null): bool
    {
        $dtype = $dtype ?? $this->dtype;
        return $this->cistype($dtype);
    }

    public function isComplexObject(mixed $value): bool
    {
        return $this->cisObject($value);
    }

    /**
     * Assert that the given shape is valid.
     */
    protected function assertShape(array $shape): void
    {
        foreach ($shape as $num) {
            if (!is_int($num)) {
                throw new InvalidArgumentException(
                    "Invalid shape numbers. It gives " . gettype($num));
            }
            if ($num < 0) {
                throw new InvalidArgumentException(
                    "Invalid shape numbers. It gives " . $num);
            }
        }
    }

    /**
     * Flatten the given nested array into a flat array.
     */
    protected function flattenArray(array|ArrayObject $nestedArray, $flatArray, int &$currentIndex = 0): int
    {
        $numElements = 0;

        if ($nestedArray instanceof ArrayObject) {
            $nestedArray = $nestedArray->getArrayCopy();
        }

        // Iterate through the nested array
        foreach ($nestedArray as $value) {
            // If the value is an array or ArrayObject, flatten it recursively
            if (is_array($value) || $value instanceof ArrayObject) {
                $numInNested = $this->flattenArray($value, $flatArray, $currentIndex);
                if ($numElements === 0) {
                    $numElements = $numInNested;
                } elseif ($numElements !== $numInNested) {
                    throw new InvalidArgumentException("The shape of the dimension is broken");
                }
            } else {
                // If the value is not an array, append it to the flat array
                $flatArray[$currentIndex++] = $value;
                $numElements++;
            }
        }

        return $numElements;
    }

    /**
     * Unflatten the given flat array into a nested array according to the given shape.
     */
    protected function unflattenArray($flatArray, &$currentIndex, array $shape): array
    {
        $size = array_shift($shape);
        $nestedArray = [];

        if (count($shape)) {
            for ($i = 0; $i < $size; $i++) {
                $nestedArray[$i] = $this->unflattenArray($flatArray, $currentIndex, $shape);
            }
        } else {
            for ($i = 0; $i < $size; $i++) {
                $nestedArray[$i] = $flatArray[$currentIndex];
                $currentIndex++;
            }
        }
        return $nestedArray;
    }

    /**
     * Generate the shape of the given array.
     */
    protected function generateShape($array): array
    {
        $shape = [];

        while (is_array($array) || $array instanceof ArrayObject) {
            $shape[] = count($array);
            $array = current($array);
        }

        return $shape;
    }

    public static function mo(): MatrixOperator
    {
        if (!isset(self::$mo)) {
            self::$mo = new MatrixOperator(self::service());
        }

        return self::$mo;
    }

    public static function service(): Service
    {
        if (!isset(self::$service)) {
            self::$service = new TensorService();
//            self::$service = new MatlibPhp();
        }

        return self::$service;
    }

    public static function setService(Service $service): void
    {
        self::$service = $service;
        self::$mo = new MatrixOperator(self::service());
    }


    /**
     * Return the internal flat buffer of the tensor.
     */
    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    /**
     * Returns the data type of the tensor.
     */
    public function dtype(): int
    {
        return $this->dtype;
    }

    /**
     * Get the shape of the tensor.
     */
    public function shape(): array
    {
        return $this->shape;
    }

    /**
     * The offset of the tensor. This is used when the tensor is a view of another tensor.
     */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * Returns how many dimensions the tensor has.
     * @return int
     */
    public function ndim(): int
    {
        return count($this->shape);
    }

    public function count(): int
    {
        if (count($this->shape) == 0)
            return 0;

        return $this->shape[0];
    }

    /**
     * Returns the total number of elements in the tensor.
     */
    public function size(): int
    {
        return (int)array_product($this->shape);
    }

    /**
     * Reshape the tensor into the given shape.
     */
    public function reshape(array $shape): static
    {
        $this->assertShape($shape);

        if ($this->size() != array_product($shape)) {
            throw new InvalidArgumentException("Unmatched size to reshape: " .
                "[" . implode(',', $this->shape()) . "]=>[" . implode(',', $shape) . "]");
        }

        return new self($this->buffer(), $this->dtype(), $shape, $this->offset());
    }


    public static function fromArray(array|NDArray $array, ?int $dtype = null, $shape = null): ?static
    {
        if (empty($array)) return null;

        if ($array instanceof NDArray) {
            return new static($array->buffer(), $array->dtype(), $shape ?? $array->shape(), $array->offset());
        }

        return new static($array, $dtype, $shape);
    }

    /**
     * Convert the tensor into an array.
     */
    public function toArray()
    {
        if (count($this->shape) == 0) {
            return $this->buffer[$this->offset];
        }

        $idx = $this->offset;

        return $this->unflattenArray($this->buffer, $idx, $this->shape);
    }

    /**
     * Convert the tensor into a flat array of the buffer contents.
     */
    public function toBufferArray()
    {
        throw new Exception('toBufferArray is not implemented yet');
    }


    /**
     * Return a one matrix with the given shape.
     *
     * @param array $shape The shape of the one matrix to return.
     * @param ?int $dtype The data type of the one matrix to return. Eg: float32, int32, etc. If null, defaults to float32.
     * @return static
     */
    public static function ones(array $shape, ?int $dtype = null): static
    {
        $mo = self::mo();

        $ndArray = $mo->ones($shape, $dtype);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    /**
     * Return a one matrix like the given one.
     *
     * @param Tensor $other The tensor to copy the shape and dtype from.
     */
    public static function onesLike(Tensor $other): static
    {
        $mo = self::mo();

        $ndArray = $mo->ones($other->shape, $other->dtype);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    /**
     * Return a zero matrix with the given shape.
     * @param array $shape The shape of the zero matrix to return.
     * @param int|null $dtype The data type of the zero matrix to return. Eg: float32, int32, etc. If null, defaults to float32.
     * @return static
     */
    public static function zeros(array $shape, ?int $dtype = null): static
    {
        $mo = self::mo();

        $ndArray = $mo->zeros($shape, $dtype);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }


    /**
     * Return a zero matrix like the given one.
     *
     * @param Tensor $other The tensor to copy the shape and dtype from.
     */
    public static function zerosLike(Tensor $other): static
    {
        $mo = self::mo();

        $ndArray = $mo->zerosLike($other);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }


    /**
     * Stack an array of tensors along a specified axis.
     *
     * @param Tensor[] $tensors The array of tensors to stack.
     * @param int $axis The axis to stack along.
     *
     * @return Tensor The stacked tensor.
     */
    public static function stack(array $tensors, int $axis = 0): Tensor
    {
        // TODO: Perform validation of shapes
        // NOTE: stack expects each tensor to be equal size
        return self::cat(array_map(fn($t) => $t->unsqueeze($axis), $tensors), $axis);
    }

    /**
     * Concatenates an array of tensors along a specified dimension.
     *
     * @param Tensor[] $tensors The array of tensors to concatenate.
     * @param int $axis The dimension to concatenate along.
     *
     * @return Tensor The concatenated tensor.
     * @throws Exception
     */
    public static function cat(array $tensors, int $axis = 0): Tensor
    {
        $axis = self::safeIndex($axis, $tensors[0]->ndim());

        // TODO: Perform validation of shapes

        $resultShape = $tensors[0]->shape();
        $resultOffset = $tensors[0]->offset();
        $resultType = $tensors[0]->dtype();
        $resultShape[$axis] = array_reduce($tensors, fn($carry, $tensor) => $carry + $tensor->shape()[$axis], 0);

        // Create a new array to store the accumulated values
        $resultSize = array_product($resultShape);

        $result = self::newBuffer($resultSize, $resultType);

        // Create output tensor of same type as first

        if ($axis === 0) {
            // Handle special case for performance reasons

            $offset = 0;
            foreach ($tensors as $t) {
                for ($i = 0; $i < $t->buffer->count(); $i++) {
                    $result[$offset++] = $t->buffer()[$i];
                }
            }
        } else {
            $currentShape = 0;

            foreach ($tensors as $tensor) {
                for ($i = 0; $i < $tensor->buffer->count(); $i++) {
                    $resultIndex = 0;

                    for ($j = $tensor->ndim() - 1, $num = $i, $resultMultiplier = 1; $j >= 0; --$j) {
                        $size = $tensor->shape()[$j];
                        $index = $num % $size;
                        if ($j === $axis) {
                            $index += $currentShape;
                        }
                        $resultIndex += $index * $resultMultiplier;
                        $resultMultiplier *= $resultShape[$j];
                        $num = (int)floor($num / $size);
                    }
                    $result[$resultIndex] = $tensor->buffer()[$i];
                }

                $currentShape += $tensor->shape()[$axis];
            }
        }

        return new Tensor($result, $resultType, $resultShape, $resultOffset);
    }

    /**
     * Safely calculates the positive index within the specified size and axis.
     * @param int $index The input index.
     * @param int $size The size of the dimension.
     * @param int|null $axis The axis (optional).
     * @return int The positive index within bounds.
     * @throws Exception If the index is out of bounds.
     */
    protected static function safeIndex(int $index, int $size, ?int $axis = null): int
    {
        if ($index < -$size || $index >= $size) {
            throw new Exception("IndexError: index $index is out of bounds for axis"
                . ($axis === null ? '' : ' ' . $axis) . " with size $size"
            );
        }

        if ($index < 0) {
            // Negative indexing, ensuring positive index
            $index = (($index % $size) + $size) % $size;
        }

        return $index;
    }


    /**
     * Returns a tensor with all specified axis of input of size 1 removed.
     *
     * @param ?int $axis If given, the input will be squeezed only in the specified axis.
     *
     * @return static The squeezed tensor.
     */
    public function squeeze(?int $axis = null): static
    {
        $mo = self::mo();

        $ndArray = $mo->la()->squeeze($this, $axis);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    /**
     * Returns a tensor with all specified axis of input of size 1 removed.
     *
     * @param ?int $axis If given, the input will be squeezed only in the specified axis.
     *
     * @return static The squeezed tensor.
     */
    public function unsqueeze(?int $axis = null): static
    {
        return new Tensor(
            $this->buffer(),
            $this->dtype,
            $this->calcUnsqueezeShape($this->shape(), $axis),
            $this->offset
        );
    }

    /**
     * Helper function to calculate new shape when performing an unsqueeze operation.
     * @param array $shape The shape of the tensor.
     * @param int $axis The axis to unsqueeze.
     * @return array The new shape.
     */
    protected function calcUnsqueezeShape(array $shape, int $axis): array
    {
        // Dimension out of range (e.g., "expected to be in range of [-4, 3], but got 4")
        // + 1 since we allow inserting at the end (i.e. $axis = -1)
        $axis = self::safeIndex($axis, count($shape) + 1);

        array_splice($shape, $axis, 0, 1);

        return $shape;
    }

    /**
     * Add a tensor or scalar to this tensor. If it's a tensor, it must be the same shape, and it performs
     * an element-wise addition. If it's a scalar, it adds the scalar to every element in the tensor.
     *
     * @param Tensor|float|int $other The NDArray to add to this NDArray.
     * @return static
     */
    public function add(Tensor|float|int $other): static
    {
        $mo = self::mo();

        $ndArray = is_scalar($other) ? $mo->op($this, '+', $other) : $mo->add($this, $other);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }


    /**
     * Return a new Tensor with the sigmoid function applied to each element.
     * @return self
     */
    public function sigmoid(): self
    {
        $mo = self::mo();

        $ndArray = $mo->f(fn($x) => 1 / (1 + exp(-$x)), $this);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    /**
     * Return a new Tensor with every element multiplied by a constant.
     *
     * @param float|int $scalar The constant to multiply by.
     *
     * @return self
     */
    public function multiply(float|int $scalar): self
    {
        $mo = self::mo();

        $ndArray = $mo->la()->scal($scalar, $this);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    public function divide(float|int $scalar): self
    {
        $mo = self::mo();

        $ndArray = $mo->la()->scal(1 / $scalar, $this);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    /**
     * Calculate the dot product of this tensor and another tensor.
     */
    public function dot(Tensor $other): float
    {
        $mo = self::mo();

        return $mo->dot($this, $other);
    }

    /**
     * Calculate the cross product of this tensor and another tensor. The shapes of the tensors must be compatible for
     * cross product
     */
    public function cross(Tensor $other): Tensor
    {
        $mo = self::mo();

        $crossProduct = $mo->cross($this, $other);

        return new static($crossProduct->buffer(), $crossProduct->dtype(), $crossProduct->shape(), $crossProduct->offset());
    }

    /**
     * Return a transposed version of this Tensor.
     * @return $this
     */
    public function transpose(): self
    {
        $mo = self::mo();

        $ndArray = $mo->transpose($this);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    /**
     * Performs `L_p` normalization of inputs over specified dimension.
     *
     * @param int $p Order of the norm. Supported values are 1, 2, Infinity.
     * @param int|null $axis The axis or axes along which to perform the reduction. If null (default), reduces all dimensions.
     *
     * @return static The normalized tensor.
     */
    public function normalize(int $p = 2, ?int $axis = null): static
    {
        $mo = self::mo();

        $result = clone $this;

        $axis = $result->safeIndex($axis, $result->ndim());

        $norm = $result->norm($p, $axis, true);

        foreach ($norm->buffer as $i => $value) {
            $resultIndex = 0;
            $num = $i;
            $resultMultiplier = 1;

            for ($j = $result->ndim() - 1; $j >= 0; --$j) {
                $size = $result->shape()[$j];

                if ($j !== $axis) {
                    $index = $num % $size;
                    $resultIndex += $index * $resultMultiplier;
                    $resultMultiplier *= $result->shape()[$j];
                }

                $num = floor($num / $size);
            }

            // Divide by normalized value
            $result->buffer[$i] /= $norm->buffer[$resultIndex];
        }

        return $result;
    }

    /**
     * Returns the matrix norm or vector norm of a given tensor.
     *
     * @param int $ord Order of the norm. Supported values are 1, 2, Infinity.
     * @param int|null $axis The axis or axes along which to perform the reduction. If null (default), reduces all dimensions.
     * @param bool $keepShape If true, retains reduced shape with length 1.
     *
     * @return static
     */
    public function norm(int $ord = 2, ?int $axis = null, bool $keepShape = false): static
    {
        $mo = self::mo();

        if ($axis === null) {
            $val = pow(array_reduce($this->toBufferArray(), fn($carry, $item) => $carry + pow($item, $ord), 0), 1 / $ord);

            return new Tensor([$val], $this->dtype(), []);
        }

        // Negative indexing
        $axis = $this->safeIndex($axis, $this->ndim());

        // Calculate the shape of the resulting array after summation
        $resultShape = $this->shape();
        $resultShape[$axis] = 1; // Remove the specified axis

        // Create a new array to store the accumulated values
        $result = $this->zeros([count($this->buffer) / $this->shape()[$axis]]);

        // Iterate over the data array
        foreach ($this->buffer as $i => $value) {
            // Calculate the index in the resulting array
            $resultIndex = 0;
            $num = $i;
            $resultMultiplier = 1;

            for ($j = $this->ndim() - 1; $j >= 0; --$j) {
                $size = $this->shape()[$j];

                if ($j !== $axis) {
                    $index = $num % $size;
                    $resultIndex += $index * $resultMultiplier;
                    $resultMultiplier *= $resultShape[$j];
                }

                $num = floor($num / $size);
            }

            // Accumulate the value at the current index
            $result[$resultIndex] += pow($this->buffer[$i], $ord);
        }

        if ($ord === 1) {
            $result = $mo->op($result, '**', 1 / $ord);
        }

        if (!$keepShape) {
            array_splice($resultShape, $axis, 1);
        }

        return new static($result->buffer(), $result->dtype(), $resultShape, $result->offset());
    }


    /**
     * Clamps all elements in input into the range [ min, max ] and returns a resulting tensor.
     *
     * @param float|int $min The minimum value.
     * @param float|int $max The maximum value.
     * @return static The clamped tensor.
     */
    public function clamp(float|int $min, float|int $max): static
    {
        $mo = self::mo();

        $result = $mo->f(fn($x) => max($min, min($max, $x)), $this);

        return new static($result->buffer(), $result->dtype(), $result->shape(), $result->offset());
    }

    /**
     * Rounds elements of input to the nearest integer.
     * @return static The rounded tensor.
     */
    public function round(): static
    {
        $mo = self::mo();

        $result = $mo->f(fn($x) => round($x), $this);

        return new static($result->buffer(), $result->dtype(), $result->shape(), $result->offset());
    }

    /**
     * Performs Tensor dtype conversion.
     *
     * @param int $dtype The target data type.
     * @return static The converted tensor.
     */
    public function to(int $dtype): static
    {
        if ($this->dtype() === $dtype) {
            return $this;
        }

        $mo = self::mo();

        $ndArray = $mo->astype($this, $dtype);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    /**
     * Returns the mean value of each row of the tensor in the given axis.
     */
    public function mean(?int $axis = null, bool $keepShape = false): static|float|int
    {
        $mo = self::mo();

        $mean = $mo->mean($this, $axis);

        if ($mean instanceof NDArray) {
            $shape = $mean->shape();

            if (!$keepShape) {
                array_splice($shape, $axis, 1);
            }

            return new static($mean->buffer(), $mean->dtype(), $shape, $mean->offset());
        }

        return $mean;
    }

    /**
     * Perform mean pooling of the tensor followed by a normalization step.
     *
     * @param Tensor $other The tensor to pool of the same shape as the input tensor.
     *
     * @return Tensor The pooled tensor.
     */
    public function meanPooling(Tensor $other): Tensor
    {
        // $this->shape should be : [batchSize, seqLength, embedAxis]
        // $other->shape should be : [batchSize, seqLength]
        [$batchSize, $seqLength, $embedAxis] = $this->shape();

        $returnedData = [];
        $outIndex = 0;

        for ($i = 0; $i < $batchSize; ++$i) {
            $offset = $i * $embedAxis * $seqLength;

            for ($k = 0; $k < $embedAxis; ++$k) {
                $sum = 0;
                $count = 0;

                $otherOffset = $i * $seqLength;
                $offset2 = $offset + $k;

                // Pool over all words in sequence
                for ($j = 0; $j < $seqLength; ++$j) {
                    // index into attention mask
                    $attn = (int)$other[$i][$j];

                    $count += $attn;
                    $sum += $this[$i][$j][$k] * $attn;
                }

                $avg = $count ? $sum / $count : 0;
                $returnedData[$outIndex++] = $avg;
            }
        }

        return new Tensor($returnedData, $this->dtype(), [$batchSize, $embedAxis]);
    }

    public function newSlice(array $start, array $size) : Tensor
    {
        $mo = self::mo();

        $ndArray = $mo->la()->slice($this, $start, $size);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }

    public function slice(...$slices): Tensor
    {
        $newTensorShape = [];
        $newOffsets = [];

        for ($sliceIndex = 0; $sliceIndex < $this->ndim(); ++$sliceIndex) {
            $slice = $slices[$sliceIndex] ?? null;

            if ($slice === null) {
                $newOffsets[] = [0, $this->shape()[$sliceIndex]];
                $newTensorShape[] = $this->shape()[$sliceIndex];

            } elseif (is_int($slice)) {
                $slice = $this->safeIndex($slice, $this->shape()[$sliceIndex], $sliceIndex);
                $newOffsets[] = [$slice, $slice + 1];

            } elseif (is_array($slice) && count($slice) === 2) {
                if ($slice[0] > $slice[1]) {
                    throw new Exception("Invalid slice: " . json_encode($slice));
                }
                $offsets = [
                    max($slice[0], 0),
                    min($slice[1], $this->shape()[$sliceIndex])
                ];
                $newOffsets[] = $offsets;
                $newTensorShape[] = $offsets[1] - $offsets[0];

            } else {
                throw new Exception("Invalid slice: " . json_encode($slice));
            }
        }

        $newShape = array_map(fn($offsets) => $offsets[1] - $offsets[0], $newOffsets);

        $newBufferSize = array_reduce($newShape, fn($a, $b) => $a * $b, 1);

        $buffer = self::newBuffer($newBufferSize, $this->dtype());
        $stride = $this->stride();

        for ($i = 0; $i < $newBufferSize; ++$i) {
            $originalIndex = 0;
            for ($j = count($newShape) - 1, $num = $i; $j >= 0; --$j) {
                $size = $newShape[$j];
                $originalIndex += (($num % $size) + $newOffsets[$j][0]) * $stride[$j];
                $num = floor($num / $size);
            }
            $buffer[$i] = $this->buffer[$originalIndex];
        }

        return new Tensor($buffer, $this->dtype(), $newShape, $this->offset());
    }

    /**
     * Compute and return the stride of this tensor.
     * Stride is the jump necessary to go from one element to the next one in the specified axis.
     * @return array The stride of this tensor.
     */
    public function stride(): array
    {
        $stride = [];
        $s2 = 1;

        for ($i = $this->ndim() - 1; $i >= 0; --$i) {
            $stride[$i] = $s2;
            $s2 *= $this->shape()[$i];
        }

        return array_reverse($stride, true);
    }

    /**
     * Permutes a tensor according to the provided axes.
     * @param array $axes The axes to permute the tensor along.
     * @return Tensor The permuted tensor.
     */
    public function permute(...$axes): static
    {
        $permuted = self::mo()->transpose($this, $axes);

        return Tensor::fromArray($permuted);
    }

    /**
     * Calculate the softmax of the tensor.
     *
     */
    public function softmax(): static
    {
        return match ($this->ndim()) {
            1 => $this->unsqueeze(0)->softmax2D()->squeeze(0),
            2 => $this->softmax2D(),
            default => throw new InvalidArgumentException("Softmax is only supported for 1D and 2D tensors.")
        };
    }


    protected function softmax2D(): static
    {
        $mo = self::mo();

        $ndArray = $mo->la()->softmax($this);

        return new static($ndArray->buffer(), $ndArray->dtype(), $ndArray->shape(), $ndArray->offset());
    }


    /**
     * @return Tensor[]
     */
    public function topk(int $k = null, bool $sorted = true) : array
    {
        if ($k === null) {
            $k = $this->shape[0];
        }

        $ndim = $this->ndim();

        if ($ndim > 2) {
            throw new InvalidArgumentException("TopK is only supported for 1D and 2D tensors.");
        }

        // TODO: Switch to using the MatrixOperator after the PR is merged

        $m = $ndim == 1 ? 1 : $this->shape[0];
        $n = $ndim == 1 ? $this->shape[0] : $this->shape[1];

        $topValues = Tensor::zeros([$m, $k], dtype: $this->dtype());
        $topIndices = Tensor::zeros([$m, $k], dtype: NDArray::int32);

        $meanHeapify = function (array &$heap, int $i, int $k) {
            $smallest = $i;
            $left = 2 * $i + 1;
            $right = 2 * $i + 2;

            while ($left < $k) {
                if ($right < $k && $heap[$right]['value'] < $heap[$left]['value']) {
                    $smallest = $right;
                } else {
                    $smallest = $left;
                }

                if ($heap[$smallest]['value'] >= $heap[$i]['value']) {
                    break;
                }

                // Swap heap[i] and heap[smallest]
                $temp = $heap[$i];
                $heap[$i] = $heap[$smallest];
                $heap[$smallest] = $temp;

                $i = $smallest;
                $left = 2 * $i + 1;
                $right = 2 * $i + 2;
            }
        };


        for ($i = 0; $i < $m; $i++) {
            $idA = $this->offset + $i * $n;

            // Create an array to represent the heap and initialize with the first k elements
            $heap = [];
            for ($j = 0; $j < $k; $j++) {
                $heap[] = ['value' => $this->buffer[$idA + $j], 'index' => $j];
            }

            // Build a min-heap with the first k elements
            $k = count($heap);
            for ($j = intdiv($k, 2) - 1; $j >= 0; $j--) {
                $meanHeapify($heap, $j, $k);
            }

            // Iterate through the remaining elements in the row
            for ($j = $k; $j < $n; $j++) {
                $currentValue = $this->buffer[$idA + $j];
                if ($currentValue > $heap[0]['value']) {
                    $heap[0] = ['value' => $currentValue, 'index' => $j];
                    $meanHeapify($heap, 0, $k);
                }
            }

            if ($sorted) {
                // Sort the heap to get the top k elements in descending order
                usort($heap, fn($a, $b) => $b['value'] <=> $a['value']);
            }

            // Extract top K values and indices from the heap
            for ($j = 0; $j < $k; $j++) {
                $topValues->buffer[$this->offset + ($i * $k) + $j] = $heap[$j]['value'];
                $topIndices->buffer[$this->offset + ($i * $k )+ $j] = $heap[$j]['index'];
            }
        }

        return $ndim == 1 ? [$topValues->squeeze(0), $topIndices->squeeze(0)] : [$topValues, $topIndices];
    }


    public function max(?int $axis = null): static|int|float
    {
        $mo = self::mo();

        $max = $mo->max($this, $axis);

        if ($max instanceof NDArray) {
            return new static($max->buffer(), $max->dtype(), $max->shape(), $max->offset());
        }

        return $max;
    }

    public function argMax(?int $axis = null): static|int|float
    {
        $mo = self::mo();

        $argMax = $mo->argMax($this, $axis);

        if ($argMax instanceof NDArray) {
            return new static($argMax->buffer(), $argMax->dtype(), $argMax->shape(), $argMax->offset());
        }

        return $argMax;
    }

    public function min(?int $axis = null): static|int|float
    {
        $mo = self::mo();

        $min = $mo->min($this, $axis);

        if ($min instanceof NDArray) {
            return new static($min->buffer(), $min->dtype(), $min->shape(), $min->offset());
        }

        return $min;
    }

    public function argMin(?int $axis = null): static|int|float
    {
        $mo = self::mo();

        $argMin = $mo->argMin($this, $axis);

        if ($argMin instanceof NDArray) {
            return new static($argMin->buffer(), $argMin->dtype(), $argMin->shape(), $argMin->offset());
        }

        return $argMin;
    }


    public function offsetExists($offset): bool
    {
        if (count($this->shape) == 0)
            return false;

        if (is_array($offset)) {
            if (count($offset) != 2 ||
                !array_key_exists(0, $offset) || !array_key_exists(1, $offset) ||
                $offset[0] > $offset[1]) {
                $det = '';
                if (is_numeric($offset[0]) && is_numeric($offset[1]))
                    $det = ':[' . implode(',', $offset) . ']';
                throw new OutOfRangeException("Illegal range specification." . $det);
            }
            $start = $offset[0];
            $limit = $offset[1];
            if (self::$rangeStyle == self::RANGE_STYLE_1) {
                ++$limit;
            }
        } elseif (is_int($offset)) {
            $start = $offset;
            $limit = $offset + 1;
        } elseif ($offset instanceof Range) {
            $start = $offset->start();
            $limit = $offset->limit();
            $delta = $offset->delta();
            if ($start >= $limit || $delta != 1) {
                $det = ":[$start,$limit" . (($delta != 1) ? ",$delta" : "") . ']';
                throw new OutOfRangeException("Illegal range specification." . $det);
            }
        } else {
            throw new OutOfRangeException("Dimension must be integer");
        }
        if ($start < 0 || $limit > $this->shape[0])
            return false;
        return true;
    }

    public function offsetGet($offset): mixed
    {
        if (!$this->offsetExists($offset))
            throw new OutOfRangeException("Index is out of range");

        // For single index specification e.g. $tensor[1]
        if (is_numeric($offset)) {
            $shape = $this->shape;
            $max = array_shift($shape);

            if (count($shape) == 0) {
                $value = $this->buffer[$this->offset + $offset];
                if ($this->isComplex()) {
                    $value = new Complex($value->real, $value->imag);
                }
                return $value;
            }

            $size = (int)array_product($shape);

            return new self($this->buffer, $this->dtype, $shape, $this->offset + $offset * $size);
        }

        // For range specification e.g. $tensor[1:3]
        $shape = $this->shape;
        array_shift($shape);

        if (is_array($offset)) {
            $start = $offset[0];
            $limit = $offset[1];
            if (self::$rangeStyle == self::RANGE_STYLE_1) {
                ++$limit;
            }
        } else {
            $start = $offset->start();
            $limit = $offset->limit();
            if ($offset->delta() != 1) {
                throw new OutOfRangeException("Illegal range specification.:delta=" . $offset->delta());
            }
        }

        $rowsCount = $limit - $start;

        if (count($shape) > 0) {
            $itemSize = (int)array_product($shape);
        } else {
            $itemSize = 1;
        }
        if ($rowsCount < 0) {
            throw new OutOfRangeException('Invalid range');
        }

        array_unshift($shape, $rowsCount);
        $size = (int)array_product($shape);

        return new self($this->buffer, $this->dtype,
            $shape, $this->offset + $start * $itemSize);
    }


    public function offsetSet($offset, $value): void
    {
        if (!$this->offsetExists($offset))
            throw new OutOfRangeException("Index is out of range");

        // For range specification e.g. $tensor[1:3]
        if (is_array($offset)) {
            throw new OutOfRangeException("Unsupported to set for range specification.");
        }


        // For single index specification e.g. $tensor[1]
        $shape = $this->shape;

        $max = array_shift($shape);

        if (!count($shape)) {
            if ($this->isComplex()) {
                if (!($value instanceof Complex)) {
                    throw new InvalidArgumentException("Must be complex type");
                }
            } else {
                if (!is_scalar($value))
                    throw new InvalidArgumentException("Must be scalar type");
            }
            $this->buffer[$this->offset + $offset] = $value;
            return;
        }

        if (!($value instanceof self) || $value->shape() != $shape) {
            throw new InvalidArgumentException("Unmatched shape numbers");
        }
        $copy = $value->buffer();
        $size = (int)array_product($shape);
        $src_idx = $value->offset();
        $idx = $this->offset + $offset * $size;

        for ($i = 0; $i < $size; $i++, $idx++, $src_idx++) {
            $this->buffer[$idx] = $copy[$src_idx];
        }
    }


    public function offsetUnset($offset): void
    {
        throw new LogicException("Unsupported Operation");
    }

    public function getIterator(): Traversable
    {
        if (count($this->shape) == 0)
            return new EmptyIterator();

        $count = $this->shape[0];

        for ($i = 0; $i < $count; $i++) {
            yield $i => $this->offsetGet($i);
        }
    }


    public function getPortableSerializeMode(): bool
    {
        return $this->portableSerializeMode;
    }

    public function setPortableSerializeMode(bool $mode): void
    {
        $this->portableSerializeMode = $mode;
    }

    public function serialize(): ?string
    {
        return static::SERIALIZE_NDARRAY_KEYWORD . serialize($this->__serialize());
    }

    public function __serialize()
    {
        $mode = 'machine';
        $buffer = $this->buffer->dump();
        return [
            'm' => $mode,
            's' => $this->shape,
            'o' => $this->offset,
            't' => $this->dtype,
            'z' => count($this->buffer),
            'b' => $buffer,
        ];
    }

    public function unserialize($data): void
    {
        if (strpos($data, static::SERIALIZE_NDARRAY_KEYWORD) === 0) {
            $data = substr($data, strlen(static::SERIALIZE_NDARRAY_KEYWORD));
            $data = unserialize($data);
            if (is_array($data)) {
                $this->__unserialize($data);
                return;
            }
        } else {
            throw new RuntimeException("Invalid saved data.");
        }

        if (!($data instanceof self)) {
            throw new RuntimeException("Invalid saved data.");
        }

        $buffer = $data->buffer();
        if (get_class($data->service()) !== get_class(self::service())) {
            $newBuffer = self::service()->buffer()->Buffer($buffer->count(), $buffer->dtype());
            if ($data->service()->serviceLevel() >= Service::LV_ADVANCED &&
                self::service()->serviceLevel() >= Service::LV_ADVANCED) {
                $newBuffer->load($buffer->dump());
            } else {
                $count = $buffer->count();
                for ($i = 0; $i < $count; $i++) {
                    $newBuffer[$i] = $buffer[$i];
                }
            }
            $buffer = $newBuffer;
        }
        $this->__construct(
            $buffer,
            dtype: $data->dtype(),
            shape: $data->shape(),
            offset: $data->offset(),
        );
    }

    public function __unserialize($data)
    {
        $mode = $data['m'];
        $this->shape = $data['s'];
        $this->offset = $data['o'];
        $this->dtype = $data['t'];
        if ($mode == 'machine' || $mode == 'rindow_openblas') {
            //if($this->service->serviceLevel()<Service::LV_ADVANCED) {
            //    throw new RuntimeException('Advanced drivers are not loaded.');
            //}
            $this->buffer = self::service()->buffer()->Buffer($data['z'], $data['t']);
            $this->buffer->load($data['b']);
        } elseif ($mode == 'linear-array') {
            // Compatibility with older specifications
            $this->buffer = self::service()->buffer()->Buffer($data['z'], $data['t']);
            foreach ($data['b'] as $key => $value) {
                $this->buffer[$key] = $value;
            }
        } else {
            throw new RuntimeException('Illegal save mode: ' . $mode);
        }
    }

    public function __clone()
    {
        if (self::service()->serviceLevel() >= Service::LV_ADVANCED) {
            $newBuffer = self::service()->buffer()->Buffer(
                count($this->buffer), $this->buffer->dtype()
            );

            $newBuffer->load($this->buffer->dump());

            $this->buffer = $newBuffer;
        } elseif (self::service()->serviceLevel() >= Service::LV_BASIC) {
            $this->buffer = clone $this->buffer;
        } else {
            throw new RuntimeException('Unknown buffer type is uncloneable:' . get_class($this->buffer));
        }
    }

}