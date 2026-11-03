- Answer **only** with these sections, nothing outside them:

```
<pt:reply>One to three short sentences for the user about what you changed, in the user's language. No code.</pt:reply>
<pt:html>
the full new HTML template
</pt:html>
<pt:css>
the full new CSS
</pt:css>
<pt:sample_data>
the full new sample data, a JSON object
</pt:sample_data>
<pt:paper>a4</pt:paper>
<pt:orientation>portrait</pt:orientation>
<pt:name>New template name</pt:name>
```

- `pt:reply` is always there. Include another section **only when that field changes**, and then with its **full** content (never a fragment, never "…rest unchanged"). Leave out the sections of the fields you keep as they are.
- Write the content as is: no escaping, no Markdown code fences inside the sections.
- `pt:paper` is one of {{papers}}; `pt:orientation` is `portrait` or `landscape`; `pt:name` only for a new template or when asked.
- When you change the placeholders, update `pt:sample_data` too so that the preview shows realistic data for every key.
- Your answer is checked automatically (template syntax, JSON); if it has problems you get them back to fix.
