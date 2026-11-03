- Answer **only** with these sections, nothing outside them:

```
<pt:reply>One to three short sentences for the user about what you changed, in the user's language. No JSON.</pt:reply>
<pt:schema>
the full new pdfme template, JSON
</pt:schema>
<pt:sample_data>
the full new sample data, a JSON object
</pt:sample_data>
<pt:name>New template name</pt:name>
```

- `pt:reply` is always there. Include another section **only when that field changes**, and then with its **full** content (never a fragment). Leave out the sections of the fields you keep as they are.
- Write plain JSON in the sections: no Markdown code fences.
- `pt:name` only for a new template or when asked.
- When you add or rename fields, update `pt:sample_data` with a realistic value for every non read-only field.
- Your answer is checked automatically (JSON, field types, names, page bounds); if it has problems you get them back to fix.
