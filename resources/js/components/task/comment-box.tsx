import { router } from '@inertiajs/react';
import { MessageSquare, Pencil, Send, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { formatDateTime } from '@/lib/week';
import {
    destroy as destroyComment,
    index as commentsIndex,
    store as storeComment,
    update as updateComment,
} from '@/routes/comments';

type Mentionable = { id: number; name: string; avatar: string | null };

type CommentItem = {
    id: number;
    body: string;
    mentions: Record<string, string>;
    author: { id: number; name: string; avatar: string | null };
    created_at: string;
    edited: boolean;
    can_edit: boolean;
    can_delete: boolean;
};

/**
 * Render a stored body, swapping `@[user:42]` for the user's current name
 * (CMT-4). Text is rendered as text, never as markup.
 */
function renderBody(body: string, mentions: Record<string, string>) {
    const parts = body.split(/(@\[user:\d+\])/g);

    return parts.map((part, index) => {
        const match = /^@\[user:(\d+)\]$/.exec(part);

        if (!match) {
            return <span key={index}>{part}</span>;
        }

        const name = mentions[match[1]];

        return (
            <span
                key={index}
                className="rounded bg-sky-100 px-1 font-medium text-sky-900 dark:bg-sky-950 dark:text-sky-200"
            >
                @{name ?? 'pengguna'}
            </span>
        );
    });
}

/**
 * Comment thread for a task (6.13). Loaded on demand so opening the detail
 * sheet does not pull every thread in the project.
 */
export function CommentBox({
    taskId,
    canComment,
}: {
    taskId: number;
    canComment: boolean;
}) {
    const [comments, setComments] = useState<CommentItem[] | null>(null);
    const [mentionables, setMentionables] = useState<Mentionable[]>([]);
    const [editingId, setEditingId] = useState<number | null>(null);
    const getInitials = useInitials();

    const load = () => {
        fetch(commentsIndex(taskId).url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (data) {
                    setComments(data.comments);
                    setMentionables(data.mentionables);
                }
            })
            .catch(() => setComments([]));
    };

    // Mounted fresh per task via a key, so this only runs once per thread.
    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <section className="space-y-3">
            <h3 className="flex items-center gap-2 text-sm font-medium">
                <MessageSquare className="size-4" aria-hidden="true" />
                Komentar
                {comments !== null && (
                    <span className="text-muted-foreground tabular-nums">
                        ({comments.length})
                    </span>
                )}
            </h3>

            {comments === null ? (
                <div className="space-y-2" aria-busy="true">
                    <div className="h-12 animate-pulse rounded-md bg-muted" />
                    <div className="h-12 animate-pulse rounded-md bg-muted" />
                </div>
            ) : comments.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada komentar.
                </p>
            ) : (
                <ul className="space-y-3">
                    {comments.map((comment) => (
                        <li key={comment.id} className="flex gap-2">
                            <Avatar className="mt-0.5 size-7 shrink-0">
                                <AvatarImage
                                    src={comment.author.avatar ?? undefined}
                                    alt=""
                                />
                                <AvatarFallback className="text-[10px]">
                                    {getInitials(comment.author.name)}
                                </AvatarFallback>
                            </Avatar>

                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-baseline gap-2">
                                    <span className="text-sm font-medium">
                                        {comment.author.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {formatDateTime(comment.created_at)}
                                        {comment.edited && ' · diubah'}
                                    </span>
                                </div>

                                {editingId === comment.id ? (
                                    <CommentEditor
                                        mentionables={mentionables}
                                        initialBody={comment.body}
                                        submitLabel="Simpan"
                                        onCancel={() => setEditingId(null)}
                                        onSubmit={(body, done) =>
                                            router.patch(
                                                updateComment(comment.id).url,
                                                { body },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        setEditingId(null);
                                                        load();
                                                        done();
                                                    },
                                                    onFinish: done,
                                                },
                                            )
                                        }
                                    />
                                ) : (
                                    <p className="text-sm break-words whitespace-pre-wrap">
                                        {renderBody(
                                            comment.body,
                                            comment.mentions,
                                        )}
                                    </p>
                                )}

                                {editingId !== comment.id &&
                                    (comment.can_edit ||
                                        comment.can_delete) && (
                                        <div className="mt-1 flex gap-1">
                                            {comment.can_edit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2 text-xs"
                                                    onClick={() =>
                                                        setEditingId(comment.id)
                                                    }
                                                >
                                                    <Pencil
                                                        className="size-3"
                                                        aria-hidden="true"
                                                    />
                                                    Ubah
                                                </Button>
                                            )}
                                            {comment.can_delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2 text-xs text-destructive hover:text-destructive"
                                                    onClick={() => {
                                                        if (
                                                            confirm(
                                                                'Hapus komentar ini?',
                                                            )
                                                        ) {
                                                            router.delete(
                                                                destroyComment(
                                                                    comment.id,
                                                                ).url,
                                                                {
                                                                    preserveScroll:
                                                                        true,
                                                                    onSuccess:
                                                                        load,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    <Trash2
                                                        className="size-3"
                                                        aria-hidden="true"
                                                    />
                                                    Hapus
                                                </Button>
                                            )}
                                        </div>
                                    )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canComment && (
                <CommentEditor
                    mentionables={mentionables}
                    initialBody=""
                    submitLabel="Kirim"
                    resetOnSubmit
                    onSubmit={(body, done) =>
                        router.post(
                            storeComment(taskId).url,
                            { body },
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    load();
                                    done();
                                },
                                onFinish: done,
                            },
                        )
                    }
                />
            )}
        </section>
    );
}

/**
 * Textarea with `@` autocomplete over the project's members (CMT-3).
 */
function CommentEditor({
    mentionables,
    initialBody,
    submitLabel,
    resetOnSubmit = false,
    onCancel,
    onSubmit,
}: {
    mentionables: Mentionable[];
    initialBody: string;
    submitLabel: string;
    resetOnSubmit?: boolean;
    onCancel?: () => void;
    onSubmit: (body: string, done: () => void) => void;
}) {
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const [body, setBody] = useState(initialBody);
    const [query, setQuery] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const getInitials = useInitials();

    const suggestions =
        query === null
            ? []
            : mentionables
                  .filter((person) =>
                      person.name.toLowerCase().includes(query.toLowerCase()),
                  )
                  .slice(0, 6);

    /** Track a trailing `@word` at the caret to drive the suggestion list. */
    const syncQuery = (value: string, caret: number) => {
        const match = /@([\p{L}\p{N} ]{0,30})$/u.exec(value.slice(0, caret));
        setQuery(match ? match[1] : null);
    };

    const insertMention = (person: Mentionable) => {
        const caret = textareaRef.current?.selectionStart ?? body.length;
        const before = body.slice(0, caret).replace(/@[\p{L}\p{N} ]{0,30}$/u, '');
        const after = body.slice(caret);

        setBody(`${before}@[user:${person.id}] ${after}`);
        setQuery(null);
        textareaRef.current?.focus();
    };

    return (
        <div className="space-y-2">
            <div className="relative">
                <textarea
                    ref={textareaRef}
                    rows={3}
                    value={body}
                    onChange={(event) => {
                        setBody(event.target.value);
                        syncQuery(
                            event.target.value,
                            event.target.selectionStart,
                        );
                    }}
                    onBlur={() => setTimeout(() => setQuery(null), 150)}
                    placeholder="Tulis komentar. Ketik @ untuk menyebut anggota."
                    aria-label="Isi komentar"
                    className="min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />

                {suggestions.length > 0 && (
                    <ul
                        className="absolute bottom-full left-0 z-20 mb-1 w-64 overflow-hidden rounded-md border bg-popover shadow-md"
                        role="listbox"
                        aria-label="Saran anggota"
                    >
                        {suggestions.map((person) => (
                            <li key={person.id}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected="false"
                                    onMouseDown={(event) =>
                                        event.preventDefault()
                                    }
                                    onClick={() => insertMention(person)}
                                    className={cn(
                                        'flex min-h-11 w-full items-center gap-2 px-3 text-left text-sm hover:bg-muted',
                                    )}
                                >
                                    <Avatar className="size-6">
                                        <AvatarImage
                                            src={person.avatar ?? undefined}
                                            alt=""
                                        />
                                        <AvatarFallback className="text-[10px]">
                                            {getInitials(person.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    {person.name}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="flex justify-end gap-2">
                {onCancel && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onCancel}
                    >
                        Batal
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    disabled={busy || body.trim() === ''}
                    onClick={() => {
                        setBusy(true);
                        onSubmit(body, () => {
                            setBusy(false);

                            if (resetOnSubmit) {
                                setBody('');
                            }
                        });
                    }}
                >
                    <Send className="size-4" aria-hidden="true" />
                    {busy ? 'Mengirim…' : submitLabel}
                </Button>
            </div>
        </div>
    );
}
