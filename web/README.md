# @mgamadeus/ddd-agents

React frontend of [mgamadeus/ddd-agents](https://github.com/ddd-framework/ddd-agents), the agentic loop for backends
built with mgamadeus/ddd: the agent chat, the widget renderer and the composed-view kit. Released together with the
Composer package of the same version.

Pre-release: the API may still change between versions.

```bash
npm install @mgamadeus/ddd-agents@next
```

Entries: `@mgamadeus/ddd-agents` (provider and setup), `/provider` (the provider alone), `/chat`, `/renderer`, `/widgets`, `/kit`, `/contract`,
`/client`, `/client/testing`, `/preview`, `/image-render`, and the stylesheet `@mgamadeus/ddd-agents/styles.css`
(import it once). Non-code files (widget documentation, preview fixtures, contracts, the node schema, the
sandbox frame, SCSS tokens) are exported one subpath per file at their path below `src`. React 19 and the libraries listed under `peerDependencies` come from the application.
