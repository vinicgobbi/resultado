import { useId, useRef, useState } from 'react';
import { cn, normalized } from '@/lib/utils';

export type SearchOption = { code: string; name: string };

type Props = {
    label: string;
    options: SearchOption[];
    value: SearchOption | null;
    onChange: (option: SearchOption) => void;
    placeholder: string;
    /** Lists every option before anything is typed (short lists). */
    listAll?: boolean;
    /** Most matches listed at once. */
    limit?: number;
};

/**
 * A field to choose one option by typing part of its name: a combobox
 * whose matches float over the page, used with the mouse, the keyboard or
 * a screen reader. While searching, the current choice is the placeholder.
 */
export default function SearchSelect({
    label,
    options,
    value,
    onChange,
    placeholder,
    listAll = false,
    limit = 8,
}: Props) {
    // Null while not searching: the field shows the current choice.
    const [search, setSearch] = useState<string | null>(null);
    const [active, setActive] = useState(0);
    const listId = useId();
    const inputId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const query = normalized((search ?? '').trim());
    const matches = query
        ? options
              .filter((option) => normalized(option.name).includes(query))
              .slice(0, limit)
        : listAll
          ? options
          : [];
    const expanded = search !== null && (query !== '' || listAll);

    function choose(option: SearchOption) {
        setSearch(null);
        onChange(option);
        inputRef.current?.blur();
    }

    return (
        <div className="space-y-1">
            <label htmlFor={inputId} className="block font-semibold">
                {label}
            </label>
            <div className="relative">
                <input
                    ref={inputRef}
                    id={inputId}
                    type="text"
                    role="combobox"
                    aria-expanded={expanded}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        expanded && matches[active]
                            ? `${listId}-${matches[active].code}`
                            : undefined
                    }
                    value={search ?? value?.name ?? ''}
                    placeholder={value?.name ?? placeholder}
                    onFocus={() => {
                        setSearch('');
                        setActive(
                            Math.max(
                                listAll && value ? options.indexOf(value) : 0,
                                0,
                            ),
                        );
                    }}
                    onBlur={() => setSearch(null)}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setActive(0);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            setActive((index) =>
                                Math.min(index + 1, matches.length - 1),
                            );
                        } else if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            setActive((index) => Math.max(index - 1, 0));
                        } else if (
                            event.key === 'Enter' &&
                            expanded &&
                            matches[active]
                        ) {
                            event.preventDefault();
                            choose(matches[active]);
                        } else if (event.key === 'Escape') {
                            event.currentTarget.blur();
                        }
                    }}
                    autoComplete="off"
                    className="min-h-11 w-full rounded-xl border-2 border-line-strong bg-surface pr-9 pl-3 placeholder:text-muted focus:border-primary"
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted"
                >
                    ▾
                </span>
                {expanded && (
                    <ul
                        id={listId}
                        role="listbox"
                        aria-label={label}
                        className="absolute inset-x-0 top-full z-30 mt-1 max-h-72 origin-top animate-menu-in overflow-y-auto rounded-xl border border-line bg-surface p-1 shadow-xl"
                    >
                        {matches.length === 0 ? (
                            <li className="px-3 py-2 text-sm text-muted">
                                Nada encontrado.
                            </li>
                        ) : (
                            matches.map((option, index) => (
                                <li
                                    key={option.code}
                                    id={`${listId}-${option.code}`}
                                    role="option"
                                    aria-selected={option.code === value?.code}
                                    // Keeps the focus in the field, so the
                                    // click is not lost to its blur.
                                    onMouseDown={(event) =>
                                        event.preventDefault()
                                    }
                                    onMouseEnter={() => setActive(index)}
                                    onClick={() => choose(option)}
                                    className={cn(
                                        'flex cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2',
                                        index === active && 'bg-primary-soft',
                                    )}
                                >
                                    {option.name}
                                    {option.code === value?.code && (
                                        <span
                                            aria-hidden="true"
                                            className="text-primary"
                                        >
                                            ✓
                                        </span>
                                    )}
                                </li>
                            ))
                        )}
                    </ul>
                )}
            </div>
        </div>
    );
}
