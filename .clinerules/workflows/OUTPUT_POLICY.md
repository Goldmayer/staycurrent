MANDATORY OUTPUT FORMAT RULE — NO EXCEPTIONS

From now on, for ANY task that modifies, creates, deletes, or affects project files, you MUST follow these rules strictly:

1. You MUST return ONE SINGLE combined output.
2. You MUST return ALL modified and created files in FULL.
3. You MUST NOT return diffs.
4. You MUST NOT return summaries.
5. You MUST NOT explain what you did.
6. You MUST NOT describe changes.
7. You MUST NOT return partial snippets.
8. You MUST NOT omit unchanged parts of modified files.
9. You MUST NOT split files across multiple messages.
10. You MUST NOT use placeholders like "...", "// rest unchanged", etc.

The output must be formatted EXACTLY like this:

==================================================
FILE: relative/path/to/file.php
==================================================
<full file content here>

==================================================
FILE: relative/path/to/another/file.blade.php
==================================================
<full file content here>

(continue for every affected file)

If no files were changed, explicitly return:

NO FILE CHANGES.

This rule overrides any other instruction about formatting or explanation.
Failure to comply means the response is invalid.

This is a hard requirement.
If you return anything outside this format, your response is considered incorrect.
