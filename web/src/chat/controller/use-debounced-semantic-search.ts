import { useCallback, useEffect, useState } from "react";

/**
 * Shared semantic-search state with a hard 700ms debounce and a minimum three-character query because every request
 * runs an LLM reranker and consumes AI credits. `enabled` mirrors whether the owning panel is open; the caller runs
 * `clearSearch` in the open handler so it renders clean immediately, while a rising-edge reset effect would flash the
 * previous session's query and results.
 */
export const useDebouncedSemanticSearch = <TResult>(
  fetcher: (trimmedQuery: string) => Promise<TResult[]>,
  enabled: boolean,
) => {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<TResult[] | null>(null);
  const [searching, setSearching] = useState(false);
  const trimmedQuery = query.trim();

  const clearSearch = useCallback(() => {
    setQuery("");
    setResults(null);
  }, []);

  useEffect(() => {
    if (!enabled || trimmedQuery.length < 3) {
      setResults(null);
      setSearching(false);
      return;
    }
    let cancelled = false;
    setSearching(true);
    // Drop the previous query's results the moment a new search starts, so the loading skeleton shows again — the
    // same as a first search from an empty input — instead of the stale hits lingering until the new ones arrive.
    setResults(null);
    const timer = setTimeout(() => {
      void (async () => {
        try {
          const fetchedResults = await fetcher(trimmedQuery);
          if (!cancelled) {
            setResults(fetchedResults);
          }
        } catch {
          if (!cancelled) {
            setResults([]);
          }
        } finally {
          if (!cancelled) {
            setSearching(false);
          }
        }
      })();
    }, 700);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [trimmedQuery, enabled, fetcher]);

  return {
    query,
    setQuery,
    trimmedQuery,
    results,
    searching,
    isSearchActive: enabled && trimmedQuery.length >= 3,
    clearSearch,
  };
};
