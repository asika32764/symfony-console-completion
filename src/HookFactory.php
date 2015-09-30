<?php


namespace Stecman\Component\Symfony\Console\BashCompletion;

final class HookFactory
{
    /**
     * Hook scripts
     *
     * These are shell-specific scripts that pass required information from that shell's
     * completion system to the interface of the completion command in this module.
     *
     * The following placeholders are replaced with their value at runtime:
     *
     *     %%function_name%%      - name of the generated shell function run for completion
     *     %%program_name%%       - command name completion will be enabled for
     *     %%program_path%%       - path to program the completion is for/generated by
     *     %%completion_command%% - command to be run to compute completions
     *
     * NOTE: Comments are stripped out by HookFactory::stripComments as eval reads
     *       input as a single line, causing it to break if comments are included.
     *       While comments work using `... | source /dev/stdin`, existing installations
     *       are likely using eval as it's been part of the instructions for a while.
     *
     * @var array
     */
    protected static $hooks = array(
        // BASH Hook
        'bash' => <<<'END'
# BASH completion for %%program_path%%
function %%function_name%% {

    # Copy BASH's completion variables to the ones the completion command expects
    # These line up exactly as the library was originally designed for BASH
    local CMDLINE_CONTENTS="$COMP_LINE"
    local CMDLINE_CURSOR_INDEX="$COMP_POINT"
    local CMDLINE_WORDBREAKS="$COMP_WORDBREAKS";

    export CMDLINE_CONTENTS CMDLINE_CURSOR_INDEX CMDLINE_WORDBREAKS

    local RESULT STATUS;

    RESULT="$(%%completion_command%% </dev/null)";
    STATUS=$?;

    local cur mail_check_backup;

    mail_check_backup=$MAILCHECK
    MAILCHECK=-1

    _get_comp_words_by_ref -n : cur;

    # Check if shell provided path completion is requested
    # @see Completion\ShellPathCompletion
    if [ $STATUS -eq 200 ]; then
        _filedir;
        return 0;

    # Bail out if PHP didn't exit cleanly
    elif [ $STATUS -ne 0 ]; then
        echo -e "$RESULT";
        return $?;
    fi;

    COMPREPLY=(`compgen -W "$RESULT" -- $cur`);

    __ltrim_colon_completions "$cur";

    MAILCHECK=mail_check_backup
};

if [ "$(type -t _get_comp_words_by_ref)" == "function" ]; then
    complete -F %%function_name%% "%%program_name%%";
else
    >&2 echo "Completion was not registered for %%program_name%%:";
    >&2 echo "The 'bash-completion' package is required but doesn't appear to be installed.";
fi
END

        // ZSH Hook
        , 'zsh' => <<<'END'
# ZSH completion for %%program_path%%
function %%function_name%% {
    local -x CMDLINE_CONTENTS="$words"
    local -x CMDLINE_CURSOR_INDEX
    (( CMDLINE_CURSOR_INDEX = ${#${(j. .)words[1,CURRENT]}} ))

    local RESULT STATUS
    RESULT=("${(@f)$( %%completion_command%% )}")
    STATUS=$?;

    # Check if shell provided path completion is requested
    # @see Completion\ShellPathCompletion
    if [ $STATUS -eq 200 ]; then
        _path_files;
        return 0;

    # Bail out if PHP didn't exit cleanly
    elif [ $STATUS -ne 0 ]; then
        echo -e "$RESULT";
        return $?;
    fi;

    compadd -- $RESULT
};

compdef %%function_name%% "%%program_name%%";
END
    );

    /**
     * Return the names of shells that have hooks
     *
     * @return string[]
     */
    public static function getShellTypes()
    {
        return array_keys(self::$hooks);
    }

    /**
     * Return a completion hook for the specified shell type
     *
     * @param string $type - a key from self::$hooks
     * @param string $programPath
     * @param string $programName
     * @param bool   $multiple
     *
     * @return string
     */
    public function generateHook($type, $programPath, $programName = null, $multiple = false)
    {
        if (!isset(self::$hooks[$type])) {
            throw new \RuntimeException(sprintf(
                "Cannot generate hook for unknown shell type '%s'. Available hooks are: %s",
                $type,
                implode(', ', self::getShellTypes())
            ));
        }

        // Use the program path if an alias/name is not given
        $programName = $programName ?: $programPath;

        if ($multiple) {
            $completionCommand = '$1 _completion';
        } else {
            $completionCommand = $programPath . ' _completion';
        }

        return str_replace(
            array(
                '%%function_name%%',
                '%%program_name%%',
                '%%program_path%%',
                '%%completion_command%%',
            ),
            array(
                $this->generateFunctionName($programPath, $programName),
                $programName,
                $programPath,
                $completionCommand
            ),
            $this->stripComments(self::$hooks[$type])
        );
    }

    /**
     * Generate a function name that is unlikely to conflict with other generated function names in the same shell
     */
    protected function generateFunctionName($programPath, $programName)
    {
        return sprintf(
            '_%s_%s_complete',
            $this->sanitiseForFunctionName(basename($programName)),
            substr(md5($programPath), 0, 16)
        );
    }


    /**
     * Make a string safe for use as a shell function name
     *
     * @param string $name
     * @return string
     */
    protected function sanitiseForFunctionName($name)
    {
        $name = str_replace('-', '_', $name);
        return preg_replace('/[^A-Za-z0-9_]+/', '', $name);
    }

    /**
     * Strip '#' style comments from a string
     *
     * BASH's eval doesn't work with comments as it removes line breaks, so comments have to be stripped out
     * for this method of sourcing the hook to work. Eval seems to be the most reliable method of getting a
     * hook into a shell, so while it would be nice to render comments, this stripping is required for now.
     *
     * @param string $script
     * @return string
     */
    protected function stripComments($script)
    {
        return preg_replace('/(^\s*\#.*$)/m', '', $script);
    }
}
