<?php
declare(strict_types=1);

namespace Smile\GdprDump\Converter\Proxy;

use InvalidArgumentException;
use RuntimeException;
use Smile\GdprDump\Converter\ConverterInterface;
use Smile\GdprDump\Tokenizer\PhpTokenizer;
use Smile\GdprDump\Tokenizer\Token;

class Conditional implements ConverterInterface
{
    /**
     * @var PhpTokenizer
     */
    private $tokenizer;

    /**
     * @var string
     */
    private $condition;

    /**
     * @var ConverterInterface
     */
    private $ifTrueConverter;

    /**
     * @var ConverterInterface
     */
    private $ifFalseConverter;

    /**
     * @var string[]
     */
    private $statementBlacklist = ['<?php', '<?', '?>'];

    /**
     * @var string[]
     */
    private $functionWhitelist = [
        'addslashes', 'chr', 'date', 'empty', 'implode', 'is_null', 'is_numeric', 'lcfirst', 'ltrim',
        'md5', 'number_format', 'preg_match', 'rtrim', 'sha1', 'sprintf', 'str_pad', 'str_repeat',
        'htmlentities', 'str_replace', 'str_word_count', 'strchr', 'strcmp', 'strcspn', 'stripcslashes',
        'stripos', 'stripslashes', 'stristr', 'strnatcasecmp', 'strnatcmp', 'strncasecmp', 'strncmp',
        'strpos', 'strrchr', 'strrev', 'htmlspecialchars', 'strripos', 'strrpos', 'strspn', 'strstr',
        'strtolower', 'strtoupper', 'strtr', 'substr', 'substr_compare', 'substr_count', 'substr_replace',
        'time', 'trim', 'ucfirst', 'ucwords', 'vsprintf', 'wordwrap',
    ];

    /**
     * @param array $parameters
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function __construct(array $parameters)
    {
        if (!isset($parameters['condition']) || $parameters['condition'] === '') {
            throw new InvalidArgumentException('The parameter "condition" is required.');
        }

        if (!isset($parameters['if_true_converter']) && !isset($parameters['if_false_converter'])) {
            throw new InvalidArgumentException(
                'The conditional converter requires a "if_true_converter" and/or "if_false_converter" parameter.'
            );
        }

        $this->tokenizer = new PhpTokenizer();
        $this->condition = $this->prepareCondition($parameters['condition']);

        if (isset($parameters['if_true_converter'])) {
            $this->ifTrueConverter = $parameters['if_true_converter'];
        }

        if (isset($parameters['if_false_converter'])) {
            $this->ifFalseConverter = $parameters['if_false_converter'];
        }
    }

    /**
     * @inheritdoc
     * @SuppressWarnings(PHPMD.EvalExpression)
     */
    public function convert($value, array $context = [])
    {
        $result = eval($this->condition);

        if ($result) {
            if ($this->ifTrueConverter) {
                $value = $this->ifTrueConverter->convert($value, $context);
            }
        } elseif ($this->ifFalseConverter) {
            $value = $this->ifFalseConverter->convert($value, $context);
        }

        return $value;
    }

    /**
     * Prepare the condition.
     *
     * @param string $condition
     * @return string
     */
    private function prepareCondition(string $condition): string
    {
        // Sanitize the condition
        $condition = $this->sanitizeCondition($condition);

        // Validate the condition
        $this->validateCondition($this->removeQuotedValues($condition));

        // Parse the condition and return the result
        return $this->parseCondition($condition);
    }

    /**
     * Sanitize the condition.
     *
     * @param string $condition
     * @return string
     * @throws RuntimeException
     */
    private function sanitizeCondition(string $condition): string
    {
        // Remove line breaks
        $condition = preg_replace('/[\r\n]+/', ' ', $condition);

        // Add instruction separator
        if (substr($condition, -1) !== ';') {
            $condition .= ';';
        }

        // Add return statement
        if (substr($condition, 0, 6) !== 'return') {
            $condition = 'return ' . $condition;
        }

        return $condition;
    }

    /**
     * Validate the condition.
     *
     * @param string $condition
     * @throws RuntimeException
     */
    private function validateCondition(string $condition)
    {
        // Prevent usage of "=" operator
        if (preg_match('/[^=!]=[^=]/', $condition)) {
            throw new RuntimeException('The operator "=" is not allowed in converter conditions.');
        }

        // Prevent usage of "$" character
        if (preg_match('/\$/', $condition)) {
            throw new RuntimeException('The character "$" is not allowed in converter conditions.');
        }

        // Prevent the use of some statements
        foreach ($this->statementBlacklist as $statement) {
            if (strpos($condition, $statement) !== false) {
                $message = sprintf('The statement "%s" is not allowed in converter conditions.', $statement);
                throw new RuntimeException($message);
            }
        }

        // Prevent the use of static functions
        if (preg_match('/::(\w+) *\(/', $condition)) {
            throw new RuntimeException('Static functions are not allowed in converter conditions.');
        }

        // Allow only specific functions
        if (preg_match_all('/(\w+) *\(/', $condition, $matches)) {
            foreach ($matches[1] as $function) {
                if (!in_array($function, $this->functionWhitelist)) {
                    $message = sprintf('The function "%s" is not allowed in converter conditions.', $function);
                    throw new RuntimeException($message);
                }
            }
        }
    }

    /**
     * Parse the tokens that represent the condition, and return the parsed condition.
     *
     * @param string $condition
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function parseCondition(string $condition): string
    {
        // Split the condition into PHP tokens
        $tokens = $this->tokenizer->tokenize('<?php ' . $condition . ' ?>');
        $tokenCount = count($tokens);
        $result = '';
        $index = -1;

        foreach ($tokens as $token) {
            ++$index;

            // Skip characters representing a variable
            if ($this->isVariableToken($token)) {
                continue;
            }

            // Replace SQL column names by their values in the condition
            if ($token->getType() === T_STRING
                && $index >= 2 && $index <= $tokenCount - 3
                && $tokens[$index - 1]->getValue() === '{' && $tokens[$index - 2]->getValue() === '{'
                && $tokens[$index + 1]->getValue() === '}' && $tokens[$index + 2]->getValue() === '}'
            ) {
                $result .= "\$context['row_data']['{$token->getValue()}']";
                continue;
            }

            // Replace SQL variable names by their values in the condition
            if ($token->getType() === T_STRING && $index >= 1 && $tokens[$index - 1]->getValue() === '@') {
                $result .= "\$context['vars']['{$token->getValue()}']";
                continue;
            }

            $result .= $token->getValue();
        }

        // Remove the opening and closing tag that were added to generate the tokens
        $result = $this->removePhpTags($result);

        return $result;
    }

    /**
     * Remove quoted values from a variable,
     * e.g. "$s = 'value'" is converted to "$s = ''"
     *
     * @param string $input
     * @return string
     */
    private function removeQuotedValues(string $input): string
    {
        // Split the condition into PHP tokens
        $tokens = $this->tokenizer->tokenize('<?php ' . $input . ' ?>');
        $result = '';

        foreach ($tokens as $token) {
            // Remove quoted values
            $result .= $token->getType() === T_CONSTANT_ENCAPSED_STRING ? "''" : $token->getValue();
        }

        // Remove the opening and closing tag that were added to generate the tokens
        $result = $this->removePhpTags($result);

        return $result;
    }

    /**
     * Remove opening and closing PHP tags from a string.
     *
     * @param string $input
     * @return string
     */
    private function removePhpTags(string $input): string
    {
        $input = ltrim($input, '<?php ');
        $input = rtrim($input, ' ?>');

        return $input;
    }

    /**
     * Check if the token represents a variable.
     *
     * @param Token $token
     * @return bool
     */
    private function isVariableToken(Token $token): bool
    {
        if ($token->getType() !== Token::T_UNKNOWN) {
            return false;
        }

        $value = $token->getValue();

        return $value === '{' || $value === '}' || $value === '@';
    }
}
