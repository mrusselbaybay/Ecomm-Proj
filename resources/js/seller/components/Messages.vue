<!-- resources/js/seller/components/Messages.vue -->
<template>
    <div class="msg-page" :class="{ 'has-active': !!activeConversationId }">
        <header class="msg-page-header">
            <div>
                <h2 class="msg-page-title">Messages</h2>
                <p class="msg-page-subtitle">Chat with buyers about their orders.</p>
            </div>
            <div class="msg-page-header-actions">
                <button type="button" class="btn-outline btn-sm" :disabled="isExporting" @click="exportCsv">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m7 10 5 5 5-5" /><path d="M12 15V3" />
                    </svg>
                    {{ isExporting ? 'Exporting…' : 'Export Messages' }}
                </button>

                <div class="msg-header-qr" ref="headerQrEl">
                    <button type="button" class="btn-primary btn-sm" :aria-expanded="showHeaderQuickReplies" @click="showHeaderQuickReplies = !showHeaderQuickReplies">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 3 14h7l-1 8 10-12h-7l1-8Z" /></svg>
                        Quick Replies
                    </button>
                    <Transition name="pop">
                    <div v-if="showHeaderQuickReplies" class="msg-header-qr-dropdown">
                        <p v-if="!activeConversationId" class="msg-header-qr-hint">Select a conversation to insert one.</p>
                        <button
                            v-for="qr in quickReplies"
                            :key="qr.label"
                            type="button"
                            class="msg-header-qr-item"
                            :disabled="!activeConversationId"
                            @click="applyQuickReply(qr); showHeaderQuickReplies = false"
                        >
                            <span class="msg-header-qr-item-label">{{ qr.label }}</span>
                            <span class="msg-header-qr-item-preview">{{ qr.text }}</span>
                        </button>
                    </div>
                    </Transition>
                </div>
            </div>
        </header>
        <p v-if="exportError" class="save-msg error" style="margin: -0.5rem 0 0.75rem">{{ exportError }}</p>

        <div class="msg-filter-tabs" role="tablist" aria-label="Filter conversations">
            <button
                v-for="tab in statusTabs"
                :key="tab.value"
                type="button"
                role="tab"
                class="msg-filter-tab"
                :class="{ active: filters.status === tab.value }"
                :aria-selected="filters.status === tab.value"
                @click="setFilter({ status: tab.value })"
            >
                {{ tab.label }}
                <span class="msg-filter-tab-count">{{ tabCount(tab.value) }}</span>
            </button>
        </div>

        <div class="msg-panels">
        <!-- ============ CONVERSATION LIST ============ -->
        <aside class="msg-list-panel">
            <div class="header-search msg-search">
                <span class="search-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m21 21-4.35-4.35" /></svg>
                </span>
                <input
                    type="text"
                    :value="searchInput"
                    placeholder="Search buyer, order #, product…"
                    aria-label="Search conversations"
                    @input="onSearchInput($event.target.value)"
                />
            </div>

            <div class="msg-list-scroll custom-scrollbar">
                <!-- Loading skeleton -->
                <div v-if="isLoadingConversations && !hasConversations" class="msg-skeleton-list">
                    <div v-for="n in 4" :key="n" class="msg-skeleton-card" aria-hidden="true">
                        <div class="msg-skeleton-avatar"></div>
                        <div class="msg-skeleton-lines">
                            <div class="msg-skeleton-line" style="width: 55%"></div>
                            <div class="msg-skeleton-line" style="width: 85%"></div>
                        </div>
                    </div>
                </div>

                <!-- Backend not deployed -->
                <div v-else-if="backendMissing" class="empty-state msg-list-empty">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" />
                    </svg>
                    <p style="font-weight: 700; color: #1e293b">Messaging isn't set up yet</p>
                    <p class="empty-hint">
                        This page is wired to a messaging API that doesn't exist in the backend yet.
                        Once it's built, conversations will load here automatically — no changes needed on this page.
                    </p>
                </div>

                <!-- Real error -->
                <div v-else-if="conversationsError" class="empty-state msg-list-empty">
                    <p style="font-weight: 700; color: #b91c1c">Couldn't load conversations</p>
                    <p class="empty-hint">{{ conversationsError }}</p>
                    <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="loadConversations">Try again</button>
                </div>

                <!-- No conversations at all -->
                <div v-else-if="!hasConversations && !hasActiveListFilters" class="empty-state msg-list-empty">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" />
                    </svg>
                    <p style="font-weight: 700; color: #1e293b">No conversations yet</p>
                    <p class="empty-hint">Buyer messages about orders and products will show up here.</p>
                </div>

                <!-- No results for filter/search -->
                <div v-else-if="!hasConversations" class="empty-state msg-list-empty">
                    <p style="font-weight: 700; color: #1e293b">No conversations match this filter</p>
                    <p class="empty-hint">Try a different search or filter.</p>
                    <button type="button" class="btn-outline btn-sm" style="margin-top: 0.75rem" @click="clearListFilters">Clear filters</button>
                </div>

                <!-- Conversation cards -->
                <template v-else>
                    <button
                        v-for="c in conversations"
                        :key="c.id"
                        type="button"
                        class="msg-convo-card"
                        :class="{ active: c.id === activeConversationId, unread: c.unreadCount > 0 }"
                        @click="selectConversation(c.id)"
                    >
                        <span class="msg-convo-avatar">{{ c.buyer.initials }}</span>
                        <span class="msg-convo-body">
                            <span class="msg-convo-top">
                                <span class="msg-convo-name">{{ c.buyer.name }}</span>
                                <span class="msg-convo-time">{{ formatListTime(c.updatedAt) }}</span>
                            </span>
                            <span class="msg-convo-snippet">
                                <span v-if="c.lastMessage?.senderRole === 'seller'">You: </span>{{ c.lastMessage?.body || 'No messages yet' }}
                            </span>
                            <span class="msg-convo-tags">
                                <span v-if="c.needsResponse" class="badge badge-amber">Needs Response</span>
                                <span v-else-if="c.status === 'resolved'" class="badge badge-emerald">Resolved</span>
                                <span v-else-if="c.status === 'archived'" class="badge badge-slate">Archived</span>
                                <span v-if="c.order" class="msg-convo-order">Order {{ c.order.orderNumber }}</span>
                            </span>
                        </span>
                        <span v-if="c.unreadCount > 0" class="msg-unread-badge" :aria-label="`${c.unreadCount} unread`">{{ c.unreadCount }}</span>
                    </button>
                </template>
            </div>

            <div v-if="hasConversations && conversationsMeta.lastPage > 1" class="pagination msg-list-pagination">
                <div class="pagination-controls">
                    <button class="page-btn" :disabled="conversationsMeta.currentPage === 1" @click="setFilter({ page: conversationsMeta.currentPage - 1 })">Previous</button>
                    <span class="pagination-page-indicator">Page {{ conversationsMeta.currentPage }} of {{ conversationsMeta.lastPage }}</span>
                    <button class="page-btn" :disabled="conversationsMeta.currentPage === conversationsMeta.lastPage" @click="setFilter({ page: conversationsMeta.currentPage + 1 })">Next</button>
                </div>
            </div>
        </aside>

        <!-- ============ CHAT PANEL ============ -->
        <section class="msg-chat-panel">
            <div v-if="backendMissing" class="empty-state msg-chat-placeholder">
                <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" />
                </svg>
                <p style="font-weight: 700; color: #1e293b">Messaging isn't set up yet</p>
                <p class="empty-hint">See the note in the conversation list for what's missing.</p>
            </div>

            <div v-else-if="!activeConversationId" class="empty-state msg-chat-placeholder">
                <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M8 10h8M8 14h4" /><path d="M21 12c0 4.418-4.03 8-9 8-1.06 0-2.076-.163-3.016-.463L3 21l1.502-3.754C3.55 16.081 3 14.593 3 13c0-4.418 4.03-8 9-8s9 3.582 9 8Z" />
                </svg>
                <p style="font-weight: 700; color: #1e293b">Select a conversation</p>
                <p class="empty-hint">Choose a conversation from the list to view messages.</p>
            </div>

            <template v-else>
                <!-- Chat header -->
                <header class="msg-chat-header">
                    <button type="button" class="msg-back-btn" aria-label="Back to conversation list" @click="goBackToList">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>
                    </button>

                    <div v-if="activeConversation" class="msg-chat-header-info">
                        <span class="msg-convo-avatar">{{ activeConversation.buyer.initials }}</span>
                        <div>
                            <h3 class="msg-chat-buyer-name">{{ activeConversation.buyer.name }}</h3>
                            <div class="msg-chat-header-meta">
                                <span v-if="activeConversation.order" class="msg-order-link" @click="goToOrder(activeConversation.order.id)">
                                    Order {{ activeConversation.order.orderNumber }}
                                </span>
                                <span class="badge" :class="statusBadgeClass(activeConversation.status)">{{ statusLabel(activeConversation.status) }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="msg-chat-header-info">
                        <div class="msg-skeleton-avatar"></div>
                        <div class="msg-skeleton-lines"><div class="msg-skeleton-line" style="width: 8rem"></div></div>
                    </div>

                    <div class="msg-chat-actions">
                        <button
                            v-if="activeConversation && activeConversation.status !== 'resolved'"
                            type="button"
                            class="msg-icon-btn"
                            title="Mark as resolved"
                            aria-label="Mark as resolved"
                            @click="setConversationStatus(activeConversationId, 'resolved')"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5" /></svg>
                        </button>
                        <button
                            v-else-if="activeConversation"
                            type="button"
                            class="msg-icon-btn"
                            title="Reopen conversation"
                            aria-label="Reopen conversation"
                            @click="setConversationStatus(activeConversationId, 'open')"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7" /><path d="M3 4v5h5" /></svg>
                        </button>

                        <button
                            v-if="activeConversation && activeConversation.status !== 'archived'"
                            type="button"
                            class="msg-icon-btn"
                            title="Archive"
                            aria-label="Archive conversation"
                            @click="confirmArchive"
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4" /></svg>
                        </button>

                        <button type="button" class="btn-outline btn-sm msg-details-btn" @click="showContextPanel = true">Details</button>

                        <div class="msg-more-menu" ref="moreMenuEl">
                            <button type="button" class="msg-icon-btn" title="More actions" aria-label="More actions" @click="showMoreMenu = !showMoreMenu" :aria-expanded="showMoreMenu">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.2" /><circle cx="12" cy="12" r="1.2" /><circle cx="12" cy="19" r="1.2" /></svg>
                            </button>
                            <Transition name="pop">
                            <div v-if="showMoreMenu" class="msg-more-dropdown">
                                <button type="button" class="msg-more-item danger" @click="openReportModal">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01" /><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>
                                    Report Buyer
                                </button>
                            </div>
                            </Transition>
                        </div>
                    </div>
                </header>

                <!-- Messages viewport -->
                <div ref="viewportEl" class="msg-viewport custom-scrollbar" @scroll="onViewportScroll">
                    <div v-if="isLoadingOlderMessages" class="msg-loading-older">
                        <div class="loading-spinner" style="width: 1.3rem; height: 1.3rem"></div>
                    </div>

                    <div v-if="isLoadingMessages" class="msg-skeleton-messages">
                        <div class="msg-skeleton-bubble received"></div>
                        <div class="msg-skeleton-bubble sent"></div>
                        <div class="msg-skeleton-bubble received"></div>
                    </div>

                    <p v-else-if="activeConversationError" class="msg-inline-error">
                        {{ activeConversationError }}
                        <button type="button" class="msg-retry-link" @click="selectConversation(activeConversationId)">Retry</button>
                    </p>

                    <template v-else>
                        <template v-for="item in groupedMessages" :key="item.key">
                            <div v-if="item.type === 'date'" class="msg-date-sep"><span>{{ item.label }}</span></div>
                            <div v-else class="msg-row" :class="item.message.senderRole === 'seller' ? 'sent' : 'received'">
                                <span v-if="item.message.senderRole === 'buyer'" class="msg-row-avatar">
                                    <span v-if="item.showMeta" class="msg-convo-avatar sm">{{ activeConversation?.buyer?.initials }}</span>
                                </span>
                                <div class="msg-bubble-col">
                                    <div v-if="item.message.body" class="msg-bubble" :class="item.message.senderRole === 'seller' ? 'sent' : 'received'">
                                        <p>{{ item.message.body }}</p>
                                    </div>
                                    <div v-if="item.message.attachments?.length" class="msg-attachments">
                                        <button
                                            v-for="att in item.message.attachments"
                                            :key="att.id"
                                            type="button"
                                            class="msg-attachment-chip"
                                            @click="openAttachment(att)"
                                        >
                                            <img v-if="isImageMime(att.mime)" :src="att.url" :alt="att.name" loading="lazy" />
                                            <span v-else class="msg-attachment-file">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                                                {{ att.name }}
                                            </span>
                                        </button>
                                    </div>
                                    <div v-if="item.showMeta || item.message.status === 'failed'" class="msg-meta" :class="item.message.senderRole">
                                        <span>{{ formatTime(item.message.createdAt) }}</span>
                                        <template v-if="item.message.senderRole === 'seller'">
                                            <span v-if="item.message.status === 'sending'">Sending…</span>
                                            <span v-else-if="item.message.status === 'delivered'">Delivered</span>
                                            <span v-else-if="item.message.status === 'read'">Read</span>
                                            <span v-else-if="item.message.status === 'sent'">Sent</span>
                                            <span v-else-if="item.message.status === 'failed'" class="msg-failed">
                                                Failed ·
                                                <button type="button" class="msg-retry-link" @click="retryMessage(item.message.id)">Retry</button>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>

                <button v-if="newIncomingCount > 0 && !isAtBottom" type="button" class="msg-new-messages-btn" @click="jumpToNewMessages">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7 7 7-7" /></svg>
                    New message{{ newIncomingCount > 1 ? 's' : '' }}
                </button>
                <span class="sr-only" aria-live="polite">{{ newIncomingCount > 0 ? `${newIncomingCount} new message${newIncomingCount > 1 ? 's' : ''} from buyer` : '' }}</span>

                <!-- Composer -->
                <div class="msg-composer">
                    <p v-if="activeConversation && activeConversation.status !== 'open'" class="msg-composer-notice">
                        This conversation is {{ activeConversation.status }}.
                        <button type="button" class="msg-retry-link" @click="setConversationStatus(activeConversationId, 'open')">Reopen it</button>
                        to keep replying.
                    </p>

                    <div class="msg-quick-replies">
                        <button v-for="qr in quickReplies" :key="qr.label" type="button" class="feedback-quick-reply-btn" @click="applyQuickReply(qr)">
                            {{ qr.label }}
                        </button>
                    </div>

                    <div v-if="stagedAttachments.length" class="msg-staged-attachments">
                        <div v-for="att in stagedAttachments" :key="att.localId" class="msg-staged-item">
                            <img v-if="att.previewUrl" :src="att.previewUrl" :alt="att.name" />
                            <span v-else class="msg-attachment-file">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                            </span>
                            <span class="msg-staged-name">{{ att.name }}</span>
                            <span v-if="att.uploading" class="msg-staged-progress">{{ att.progress }}%</span>
                            <span v-else-if="att.error" class="msg-staged-error">{{ att.error }}</span>
                            <button type="button" class="msg-staged-remove" :aria-label="`Remove ${att.name}`" @click="removeAttachment(att.localId)">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
                            </button>
                        </div>
                    </div>

                    <div class="msg-composer-box">
                        <button type="button" class="msg-icon-btn" title="Attach file" aria-label="Attach file" @click="fileInputEl?.click()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" /></svg>
                        </button>
                        <input ref="fileInputEl" type="file" class="msg-file-input" accept="image/png,image/jpeg,image/webp,application/pdf" multiple @change="onFilePicked" />

                        <textarea
                            ref="textareaEl"
                            v-model="draftText"
                            class="msg-textarea"
                            rows="1"
                            placeholder="Write your message…"
                            :disabled="!!activeConversation && activeConversation.status !== 'open'"
                            @input="onDraftInput"
                            @keydown.enter.exact.prevent="onSend"
                        ></textarea>

                        <button
                            type="button"
                            class="msg-send-btn"
                            :disabled="!canSend"
                            aria-label="Send message"
                            @click="onSend"
                        >
                            <svg v-if="!isSending" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z" /><path d="M22 2 11 13" /></svg>
                            <span v-else class="loading-spinner" style="width: 1rem; height: 1rem; border-width: 2px"></span>
                        </button>
                    </div>
                    <p v-if="sendError" class="save-msg error">{{ sendError }}</p>
                </div>
            </template>
        </section>

        <!-- ============ CONTEXT PANEL (buyer / order / product) ============ -->
        <aside class="msg-context-panel custom-scrollbar" :class="{ open: showContextPanel }" aria-label="Buyer and order details">
            <div class="msg-context-header">
                <h3>Details</h3>
                <button type="button" class="modal-close" aria-label="Close details" @click="showContextPanel = false">
                    <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                </button>
            </div>

            <div v-if="activeConversation" class="msg-context-body">
                <div class="msg-context-buyer">
                    <span class="msg-convo-avatar lg">{{ activeConversation.buyer.initials }}</span>
                    <h4>{{ activeConversation.buyer.name }}</h4>
                </div>

                <div class="msg-ctx-tabs" role="tablist" aria-label="Buyer details view">
                    <button
                        type="button"
                        role="tab"
                        :class="{ active: contextTab === 'overview' }"
                        :aria-selected="contextTab === 'overview'"
                        @click="contextTab = 'overview'"
                    >
                        Overview
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :class="{ active: contextTab === 'order' }"
                        :aria-selected="contextTab === 'order'"
                        @click="contextTab = 'order'"
                    >
                        Order
                    </button>
                </div>

                <template v-if="contextTab === 'overview'">
                    <div class="msg-context-section">
                        <div class="msg-context-label-row">
                            <p class="msg-context-label">Shared Files</p>
                            <span v-if="sharedMedia.length" class="msg-context-count">{{ sharedMedia.length }}</span>
                        </div>
                        <div v-if="sharedMedia.length" class="msg-media-grid">
                            <button
                                v-for="(att, i) in sharedMedia"
                                :key="att.id ?? i"
                                type="button"
                                class="msg-media-thumb"
                                @click="openAttachment(att)"
                            >
                                <img v-if="isImageMime(att.mime)" :src="att.url" :alt="att.name" loading="lazy" />
                                <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6" /></svg>
                            </button>
                        </div>
                        <p v-else class="msg-context-empty">No files shared in this conversation yet.</p>
                    </div>
                </template>

                <template v-else>
                    <div v-if="activeConversation.order" class="msg-context-section">
                        <button type="button" class="msg-order-card" @click="goToOrder(activeConversation.order.id)">
                            <img v-if="activeConversation.product?.image" :src="activeConversation.product.image" :alt="activeConversation.product.name" />
                            <div v-else class="feedback-product-thumb-placeholder" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></svg>
                            </div>
                            <div class="msg-order-card-info">
                                <p class="msg-order-card-product">{{ activeConversation.product?.name || 'Order' }}</p>
                                <p v-if="activeConversation.product?.variant" class="msg-order-card-variant">{{ activeConversation.product.variant }}</p>
                                <p class="msg-order-card-number">{{ activeConversation.order.orderNumber }}</p>
                            </div>
                        </button>
                        <div class="msg-context-facts">
                            <div><span>Status</span><strong>{{ activeConversation.order.status }}</strong></div>
                            <div v-if="activeConversation.order.deliveryStatus"><span>Delivery</span><strong>{{ activeConversation.order.deliveryStatus }}</strong></div>
                            <div v-if="activeConversation.product?.quantity"><span>Qty</span><strong>{{ activeConversation.product.quantity }}</strong></div>
                            <div v-if="activeConversation.order.total != null"><span>Total</span><strong>{{ formatCurrency(activeConversation.order.total) }}</strong></div>
                        </div>

                        <!-- Real order_status_history rows — same source Deliveries
                             and Courier Handover already read from. -->
                        <template v-if="activeConversation.order.timeline?.length">
                            <p class="msg-context-label" style="margin-top: 1.1rem">Order Timeline</p>
                            <div class="msg-track-list">
                                <div
                                    v-for="(step, i) in activeConversation.order.timeline"
                                    :key="i"
                                    class="msg-track-item"
                                >
                                    <div class="msg-track-dot-wrap">
                                        <span class="msg-track-dot done">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M4 10l4 4 8-8" /></svg>
                                        </span>
                                        <span v-if="i < activeConversation.order.timeline.length - 1" class="msg-track-line"></span>
                                    </div>
                                    <div>
                                        <p class="msg-track-title">{{ step.status }}</p>
                                        <p class="msg-track-meta">{{ formatTrackTime(step.at) }}<template v-if="step.note"> · {{ step.note }}</template></p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p v-else class="msg-context-empty">No order linked to this conversation.</p>
                </template>
            </div>
        </aside>
        <div v-if="showContextPanel" class="msg-context-backdrop" @click="showContextPanel = false"></div>
        </div>

        <!-- Image preview modal -->
        <Transition name="modal-fade">
        <div v-if="previewImageUrl" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Attachment preview" @click.self="previewImageUrl = null">
            <div class="feedback-image-modal">
                <button type="button" class="modal-close feedback-image-close" aria-label="Close preview" @click="previewImageUrl = null">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
                </button>
                <img :src="previewImageUrl" alt="Attachment preview" />
            </div>
        </div>
        </Transition>

        <!-- Archive confirm modal -->
        <Transition name="modal-fade">
        <div v-if="pendingArchive" class="modal-overlay" @click.self="pendingArchive = false">
            <div class="modal-panel">
                <div class="modal-header">
                    <h3>Archive this conversation?</h3>
                    <button type="button" class="modal-close" aria-label="Close" @click="pendingArchive = false">
                        <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                    </button>
                </div>
                <p class="modal-desc">You can find it again under the Archived filter.</p>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" style="flex: 1" @click="pendingArchive = false">Cancel</button>
                    <button type="button" class="btn-primary" style="flex: 1" @click="runArchive">Archive</button>
                </div>
            </div>
        </div>
        </Transition>

        <!-- Report buyer modal -->
        <Transition name="modal-fade">
        <div v-if="showReportModal" class="modal-overlay" @click.self="showReportModal = false">
            <div class="modal-panel">
                <div class="modal-header">
                    <h3>Report {{ activeConversation?.buyer?.name || 'this buyer' }}?</h3>
                    <button type="button" class="modal-close" aria-label="Close" @click="showReportModal = false">
                        <svg class="icon" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                    </button>
                </div>
                <p class="modal-desc">This flags the conversation for review. Only report if there's an actual policy violation.</p>
                <label class="field-label" for="msg-report-reason">Reason</label>
                <textarea id="msg-report-reason" v-model="reportReason" class="field-input" rows="3" placeholder="What happened?"></textarea>
                <div class="modal-actions">
                    <button type="button" class="btn-outline" style="flex: 1" @click="showReportModal = false">Cancel</button>
                    <button type="button" class="btn-danger" style="flex: 1" :disabled="!reportReason.trim() || isReporting" @click="confirmReport">
                        {{ isReporting ? 'Reporting…' : 'Report' }}
                    </button>
                </div>
            </div>
        </div>
        </Transition>
    </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue';
import { useMessaging } from '../composables/useMessaging';

const {
    conversations,
    conversationsMeta,
    isLoadingConversations,
    conversationsError,
    backendMissing,
    hasConversations,
    filters,
    setFilter,
    loadConversations,

    isExporting,
    exportError,
    exportCsv,

    activeConversationId,
    activeConversation,
    activeConversationError,
    openConversation,
    closeActiveConversation,
    setConversationStatus,
    reportBuyer,

    messages,
    messagesMeta,
    isLoadingMessages,
    isLoadingOlderMessages,
    loadOlderMessages,

    isSending,
    sendError,
    sendMessage,
    retryMessage,

    newIncomingCount,
    pollNewMessages,
    clearNewIncoming,
    MESSAGE_POLL_MS,

    validateAttachment,
    uploadAttachment,

    getDraft,
    saveDraft,
    clearDraft,
} = useMessaging();

const statusTabs = [
    { value: 'all', label: 'Inbox' },
    { value: 'unread', label: 'Unread' },
    { value: 'needs_response', label: 'Needs Response' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'archived', label: 'Archived' },
];

// Practical, editable-before-sending templates — inserted into the
// composer, never sent automatically.
const quickReplies = [
    { label: 'Order status update', text: "Hi! Quick update on your order — it's currently being processed and you'll get a tracking number as soon as it ships." },
    { label: 'Shipping delay', text: "Hi, just a heads up that your order is running a little behind. We're on it and will let you know the moment it ships — sorry for the wait!" },
    { label: 'Return instructions', text: "No problem! To start a return, reply with your order number and the reason, and we'll send over the next steps." },
    { label: 'Refund update', text: "Your refund has been processed on our end. It can take a few business days to reflect depending on your bank or payment method." },
    { label: 'Thank-you response', text: "Thank you so much for your order and for reaching out! Let us know if there's anything else we can help with." },
];

const searchInput = ref('');
const showContextPanel = ref(false);
const contextTab = ref('overview');
const showMoreMenu = ref(false);
const moreMenuEl = ref(null);
const showHeaderQuickReplies = ref(false);
const headerQrEl = ref(null);
const draftText = ref('');
const stagedAttachments = ref([]);
const viewportEl = ref(null);
const textareaEl = ref(null);
const fileInputEl = ref(null);
const isAtBottom = ref(true);
const previewImageUrl = ref(null);
const pendingArchive = ref(false);
const showReportModal = ref(false);
const reportReason = ref('');
const isReporting = ref(false);

const hasActiveListFilters = computed(
    () => !!(filters.value.search || (filters.value.status && filters.value.status !== 'all')),
);

function tabCount(value) {
    const counts = conversationsMeta.value.statusCounts || {};
    const map = { all: 'all', unread: 'unread', needs_response: 'needsResponse', resolved: 'resolved', archived: 'archived' };

    return counts[map[value]] ?? 0;
}

let searchDebounce = null;
function onSearchInput(value) {
    searchInput.value = value;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => setFilter({ search: value.trim() }), 350);
}
function clearListFilters() {
    searchInput.value = '';
    setFilter({ search: '', status: 'all', page: 1 });
}

function formatTrackTime(iso) {
    if (!iso) {
return '';
}

    return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function formatListTime(iso) {
    if (!iso) {
return '';
}

    const d = new Date(iso);
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();

    if (sameDay) {
return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

    const yest = new Date(now);
    yest.setDate(now.getDate() - 1);

    if (d.toDateString() === yest.toDateString()) {
return 'Yesterday';
}

    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}
function formatTime(iso) {
    return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}
function formatDateSeparator(d) {
    const today = new Date();
    const yest = new Date(today);
    yest.setDate(today.getDate() - 1);
    const sameDay = (a, b) => a.toDateString() === b.toDateString();

    if (sameDay(d, today)) {
return 'Today';
}

    if (sameDay(d, yest)) {
return 'Yesterday';
}

    return d.toLocaleDateString([], { month: 'long', day: 'numeric', year: d.getFullYear() !== today.getFullYear() ? 'numeric' : undefined });
}
function formatCurrency(value) {
    return `₱${Number(value ?? 0).toFixed(2)}`;
}
function isImageMime(mime) {
    return typeof mime === 'string' && mime.startsWith('image/');
}
function statusLabel(status) {
    return status === 'open' ? 'Open' : status === 'resolved' ? 'Resolved' : 'Archived';
}
function statusBadgeClass(status) {
    return status === 'resolved' ? 'badge-emerald' : status === 'archived' ? 'badge-slate' : 'badge-sky';
}

// Groups consecutive same-sender messages within 5 minutes under one
// avatar/timestamp, and inserts "Today"/"Yesterday"/date separators —
// reduces visual clutter per the design brief.
const GROUP_WINDOW_MS = 5 * 60 * 1000;
const groupedMessages = computed(() => {
    const out = [];
    let lastDateLabel = null;
    let lastSender = null;
    let lastTime = null;

    for (const m of messages.value) {
        const d = new Date(m.createdAt);
        const dateLabel = formatDateSeparator(d);

        if (dateLabel !== lastDateLabel) {
            out.push({ type: 'date', label: dateLabel, key: `date-${m.id}` });
            lastDateLabel = dateLabel;
            lastSender = null;
        }

        const grouped = m.senderRole === lastSender && lastTime && d - lastTime < GROUP_WINDOW_MS;
        out.push({ type: 'msg', message: m, showMeta: !grouped, key: m.id });
        lastSender = m.senderRole;
        lastTime = d;
    }

    return out;
});

const sharedMedia = computed(() => messages.value.flatMap((m) => m.attachments || []));

const canSend = computed(() => {
    if (isSending.value) {
return false;
}

    if (activeConversation.value && activeConversation.value.status !== 'open') {
return false;
}

    if (stagedAttachments.value.some((a) => a.uploading)) {
return false;
}

    const hasText = draftText.value.trim().length > 0;
    const hasAttachment = stagedAttachments.value.some((a) => a.uploadedId);

    return hasText || hasAttachment;
});

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}
function scrollToBottom(smooth = false) {
    const el = viewportEl.value;

    if (!el) {
return;
}

    el.scrollTo({ top: el.scrollHeight, behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto' });
}
function autosizeTextarea() {
    const el = textareaEl.value;

    if (!el) {
return;
}

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

function onDraftInput() {
    autosizeTextarea();

    if (activeConversationId.value) {
saveDraft(activeConversationId.value, draftText.value);
}
}

async function selectConversation(id) {
    if (id === activeConversationId.value) {
return;
}

    await openConversation(id);
    draftText.value = getDraft(id);
    stagedAttachments.value = [];
    isAtBottom.value = true;
    contextTab.value = 'overview';
    await nextTick();
    autosizeTextarea();
    scrollToBottom(false);

    // Let the seller start typing right away — but not into a disabled
    // composer (conversation resolved/archived), where focus would be
    // a no-op at best and confusing at worst.
    if (!activeConversation.value || activeConversation.value.status === 'open') {
        textareaEl.value?.focus();
    }
}

function goBackToList() {
    closeActiveConversation();
}

function goToOrder(orderId) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section: 'orderDetails', orderId } }));
}

function onViewportScroll() {
    const el = viewportEl.value;

    if (!el) {
return;
}

    isAtBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 40;

    if (isAtBottom.value) {
clearNewIncoming();
}

    if (el.scrollTop < 60 && messagesMeta.value.hasMore && !isLoadingOlderMessages.value) {
        const prevHeight = el.scrollHeight;
        loadOlderMessages().then(() => {
            nextTick(() => {
                el.scrollTop = el.scrollHeight - prevHeight;
            });
        });
    }
}

function jumpToNewMessages() {
    clearNewIncoming();
    scrollToBottom(true);
}

async function onSend() {
    if (!canSend.value || !activeConversationId.value) {
return;
}

    const uploaded = stagedAttachments.value.filter((a) => a.uploadedId);
    const attachmentIds = uploaded.map((a) => a.uploadedId);
    const attachmentPreviews = uploaded.map((a) => ({ id: a.uploadedId, name: a.name, mime: a.mime, url: a.previewUrl }));
    const result = await sendMessage(activeConversationId.value, draftText.value, attachmentIds, attachmentPreviews);

    if (result) {
        draftText.value = '';
        clearDraft(activeConversationId.value);
        stagedAttachments.value = [];
        await nextTick();
        autosizeTextarea();
        scrollToBottom(true);
    }
}

function applyQuickReply(template) {
    draftText.value = draftText.value ? `${draftText.value}\n${template.text}` : template.text;

    if (activeConversationId.value) {
saveDraft(activeConversationId.value, draftText.value);
}

    nextTick(autosizeTextarea);
}

// ---- attachments ----
function onFilePicked(e) {
    const files = Array.from(e.target.files || []);
    e.target.value = '';
    files.forEach(addAttachment);
}
// Always looks the item up fresh by localId rather than closing over the
// object literal created in addAttachment() — reactive arrays wrap
// pushed objects on access, so mutating a closed-over raw reference
// isn't guaranteed to touch the same reactive target the template reads
// from. Looking it up each time sidesteps that entirely.
function findStaged(localId) {
    return stagedAttachments.value.find((a) => a.localId === localId);
}

function addAttachment(file) {
    const error = validateAttachment(file);
    const localId = `att-${Date.now()}-${Math.random().toString(36).slice(2)}`;

    stagedAttachments.value = [
        ...stagedAttachments.value,
        {
            localId,
            file,
            name: file.name,
            size: file.size,
            mime: file.type,
            previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
            uploading: !error,
            progress: 0,
            uploadedId: null,
            error: error || '',
        },
    ];

    if (error) {
return;
}

    uploadAttachment(file, (pct) => {
        const staged = findStaged(localId);

        if (staged) {
staged.progress = pct;
}
    })
        .then((data) => {
            const staged = findStaged(localId);

            if (!staged) {
return;
} // removed before the upload finished

            staged.uploading = false;
            staged.uploadedId = data.id;
        })
        .catch((err) => {
            const staged = findStaged(localId);

            if (!staged) {
return;
}

            staged.uploading = false;
            staged.error = err?.message || 'Upload failed.';
        });
}
function removeAttachment(localId) {
    const item = stagedAttachments.value.find((a) => a.localId === localId);

    if (item?.previewUrl) {
URL.revokeObjectURL(item.previewUrl);
}

    stagedAttachments.value = stagedAttachments.value.filter((a) => a.localId !== localId);
}
function openAttachment(att) {
    if (isImageMime(att.mime)) {
        previewImageUrl.value = att.url;
    } else if (att.url) {
        window.open(att.url, '_blank', 'noopener');
    }
}

// ---- conversation actions ----
function confirmArchive() {
    showMoreMenu.value = false;
    pendingArchive.value = true;
}
async function runArchive() {
    if (activeConversationId.value) {
        await setConversationStatus(activeConversationId.value, 'archived');
    }

    pendingArchive.value = false;
}
function openReportModal() {
    showMoreMenu.value = false;
    reportReason.value = '';
    showReportModal.value = true;
}
async function confirmReport() {
    if (!reportReason.value.trim() || !activeConversationId.value) {
return;
}

    isReporting.value = true;
    await reportBuyer(activeConversationId.value, reportReason.value.trim());
    isReporting.value = false;
    showReportModal.value = false;
}

function onDocClick(e) {
    if (showMoreMenu.value && moreMenuEl.value && !moreMenuEl.value.contains(e.target)) {
        showMoreMenu.value = false;
    }

    if (showHeaderQuickReplies.value && headerQrEl.value && !headerQrEl.value.contains(e.target)) {
        showHeaderQuickReplies.value = false;
    }
}

// Escape closes whatever's on top — modals first, then the more-actions
// menu, then the mobile details drawer — mirroring the click-outside
// behavior above but for keyboard users, who currently have no way to
// dismiss any of these without a mouse.
function onGlobalKeydown(e) {
    if (e.key !== 'Escape') {
return;
}

    if (showReportModal.value) {
        showReportModal.value = false;
    } else if (pendingArchive.value) {
        pendingArchive.value = false;
    } else if (previewImageUrl.value) {
        previewImageUrl.value = null;
    } else if (showMoreMenu.value) {
        showMoreMenu.value = false;
    } else if (showHeaderQuickReplies.value) {
        showHeaderQuickReplies.value = false;
    } else if (showContextPanel.value) {
        showContextPanel.value = false;
    }
}

// ---- polling for new messages while a conversation is open (see
// useMessaging.js's docblock: no realtime infra exists, so this is a
// genuine interval poll, not a fabricated live-update mechanism) ----
let messagePollTimer = null;
watch(activeConversationId, (id) => {
    clearInterval(messagePollTimer);

    if (!id) {
return;
}

    messagePollTimer = setInterval(async () => {
        const before = messages.value.length;
        await pollNewMessages(isAtBottom.value);

        if (messages.value.length > before && isAtBottom.value) {
            await nextTick();
            scrollToBottom(true);
        }
    }, MESSAGE_POLL_MS);
});

onMounted(() => {
    loadConversations();
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onGlobalKeydown);
});

// The open conversation's messages already poll (see above); the
// sidebar list itself only ever loaded once on mount, so a brand-new
// thread from a buyer never appeared until the seller reloaded. Same
// 30s rhythm as the unread badge's own poll (useMessaging's
// UNREAD_POLL_MS, not exported so mirrored here as a literal).
const CONVERSATIONS_POLL_MS = 30 * 1000;
let conversationsPollTimer = null;

onMounted(() => {
    conversationsPollTimer = setInterval(() => loadConversations(), CONVERSATIONS_POLL_MS);
});

onBeforeUnmount(() => {
    clearTimeout(searchDebounce);
    clearInterval(messagePollTimer);
    clearInterval(conversationsPollTimer);
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onGlobalKeydown);
    stagedAttachments.value.forEach((a) => a.previewUrl && URL.revokeObjectURL(a.previewUrl));
});
</script>

<style scoped>
/* ============================================================
   MESSAGES — dark reskin (matches Dashboard / Orders / Inventory /
   Order Preparation / Order Details / Courier Handover / Delivery /
   Reports / Reviews). Scoped to this component, so targeting shared
   class names here (.badge-*, .btn-outline, .field-input, .pagination,
   .page-btn, .icon-lg, .empty-state, .feedback-quick-reply-btn,
   .feedback-product-thumb-placeholder, ...) only ever affects what
   this page renders. Modals (image preview, archive/report confirm)
   are left as-is, same convention every other reskinned page follows.
   ============================================================ */
.msg-page {
    --msg-surface: #161b17;
    --msg-surface-2: #1d231e;
    --msg-border: rgba(255, 255, 255, 0.08);
    --msg-border-soft: rgba(255, 255, 255, 0.06);
    --msg-ink-900: #f2f4f1;
    --msg-ink-700: #c6cbc5;
    --msg-ink-500: #97a099;
    --msg-ink-400: #6d766e;
    color: var(--msg-ink-900);
    /* The page used to just be the 3-panel row (height:100%, filling
       whatever fixed-height ancestor the internal chat scrolling relies
       on). A real title/subtitle header and a page-level tab bar sit
       above it now, so the row moved to .msg-panels and this became a
       column. SellerLayout.vue pins its own wrapper (.chat-shell) to a
       fixed viewport height for this page specifically — flex:1 +
       min-height:0 here is what lets this column fill exactly the
       space that leaves, so switching conversations (a short thread vs.
       a long one, an order with a 2-step timeline vs. none) never grows
       or shrinks the page — only each panel's own overflow scrolls. */
    display: flex;
    flex-direction: column;
    gap: 1rem;
    flex: 1;
    min-height: 0;
}

/* ---- page header + tab bar ---- */
.msg-page-header {
    flex-shrink: 0;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.msg-page-title {
    font-size: 1.4rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: var(--msg-ink-900);
    margin: 0;
}
.msg-page-subtitle {
    font-size: 0.8rem;
    color: var(--msg-ink-500);
    margin: 0.25rem 0 0;
}
.msg-page-header-actions {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-shrink: 0;
}
.msg-header-qr {
    position: relative;
}
.msg-header-qr-dropdown {
    position: absolute;
    top: calc(100% + 0.4rem);
    right: 0;
    width: 18rem;
    max-height: 20rem;
    overflow-y: auto;
    background: var(--msg-surface);
    border: 1px solid var(--msg-border);
    border-radius: 0.7rem;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
    z-index: 20;
    padding: 0.4rem;
}
.msg-header-qr-hint {
    font-size: 0.72rem;
    color: var(--msg-ink-500);
    padding: 0.4rem 0.5rem;
    margin: 0;
}
.msg-header-qr-item {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    width: 100%;
    padding: 0.5rem 0.6rem;
    background: none;
    border: none;
    border-radius: 0.5rem;
    text-align: left;
    cursor: pointer;
}
.msg-header-qr-item:hover:not(:disabled) {
    background: var(--msg-surface-2);
}
.msg-header-qr-item:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.msg-header-qr-item-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--msg-ink-900);
}
.msg-header-qr-item-preview {
    font-size: 0.71rem;
    color: var(--msg-ink-500);
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
}
.msg-page > .msg-filter-tabs {
    flex-shrink: 0;
    padding: 0 0 0.85rem;
    border-bottom: 1px solid var(--msg-border-soft);
    overflow-x: auto;
}

/* ---- the 3-panel row itself ---- */
.msg-panels {
    display: flex;
    flex: 1;
    min-height: 0;
    gap: 0.75rem;
    position: relative;
}

/* ---- panels (list / chat / context) ---- */
.msg-list-panel,
.msg-chat-panel,
.msg-context-panel {
    background: var(--msg-surface);
    border-color: var(--msg-border);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

/* Whenever 2+ panels sit side by side (tablet: list+chat, context as a
   drawer; desktop: all three) they read as one seamless frame with thin
   dividers between sections — not three separate cards with gaps — same
   as the reference's .msg-shell. Below 861px only one panel is ever
   visible at a time (see the existing .has-active rules further down),
   so each one keeps its own standalone rounded card there instead. */
@media (min-width: 861px) {
    .msg-panels {
        gap: 0;
        background: var(--msg-surface);
        border: 1px solid var(--msg-border);
        border-radius: 0.85rem;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        overflow: hidden;
    }
    .msg-list-panel,
    .msg-chat-panel,
    .msg-context-panel {
        background: transparent;
        border: none;
        border-radius: 0;
        box-shadow: none;
    }
    .msg-list-panel {
        border-right: 1px solid var(--msg-border-soft);
    }
    .msg-context-panel {
        border-left: 1px solid var(--msg-border-soft);
    }
}
/* Below 1181px the context panel stops being an inline pane and becomes
   a floating drawer (see the global responsive rules further down) — it
   needs its own solid background/shadow again there, overriding the
   "transparent, divider-only" treatment the seamless-container rule
   above gives it for the ≥861px inline case. */
@media (max-width: 1180px) {
    .msg-context-panel {
        background: var(--msg-surface);
        border-left: none;
        border-radius: 0;
        box-shadow: -10px 0 30px rgba(0, 0, 0, 0.45);
    }
}
.msg-list-scroll {
    border-top-color: var(--msg-border-soft);
}
.msg-list-pagination {
    border-top-color: var(--msg-border-soft);
}

/* ---- shared primitives ----
   The visual "pill" (background/border/radius) belongs on the <input>
   itself, never the .header-search wrapper — the wrapper has no
   padding of its own, so a block-level div defaults to full width
   (unlike the input's fixed 15rem), and painting a second border on it
   stretched the dark box past the input's real edges — exactly the
   "icon floating outside the bar" bug this fixes. */
.msg-page .header-search input {
    background: var(--msg-surface-2);
    border: 1px solid var(--msg-border);
    color: var(--msg-ink-900);
}
.msg-page .header-search input::placeholder {
    color: var(--msg-ink-400);
}
.msg-page .header-search .search-icon {
    color: var(--msg-ink-500);
}
.msg-page .btn-outline {
    background: var(--msg-surface-2);
    border-color: var(--msg-border);
    color: var(--msg-ink-900);
}
.msg-page .btn-outline:hover:not(:disabled) {
    background: var(--msg-surface);
}
.msg-page .field-input {
    background: var(--msg-surface-2);
    border-color: var(--msg-border);
    color: var(--msg-ink-900);
}
.msg-page .field-label {
    color: var(--msg-ink-500);
}
.msg-page .save-msg.error {
    color: #f7a49f;
}
.msg-page .icon-lg {
    color: var(--msg-ink-400);
}
.msg-page .empty-state p,
.msg-page .empty-hint {
    color: var(--msg-ink-500);
}
.msg-page .empty-state p[style*='#1e293b'] {
    color: var(--msg-ink-900) !important;
}
.msg-page .pagination {
    color: var(--msg-ink-500);
}
.msg-page .page-btn {
    background: var(--msg-surface-2);
    border-color: var(--msg-border);
    color: var(--msg-ink-700);
}
.msg-page .page-btn:disabled {
    opacity: 0.4;
}
.msg-page .badge-amber {
    background: rgba(251, 191, 125, 0.16);
    color: #fbbf7d;
    border: 1px solid rgba(251, 191, 125, 0.3);
}
.msg-page .badge-emerald {
    background: rgba(15, 118, 110, 0.2);
    color: #5eead4;
    border-color: rgba(15, 118, 110, 0.35);
}
.msg-page .badge-slate {
    background: var(--msg-surface-2);
    color: var(--msg-ink-500);
    border: 1px solid var(--msg-border);
}
.msg-page .badge-sky {
    background: rgba(18, 63, 143, 0.28);
    color: #9dc2ef;
    border-color: rgba(18, 63, 143, 0.4);
}
.msg-page .feedback-quick-reply-btn {
    color: #5eead4;
    background: rgba(20, 184, 166, 0.1);
    border-color: rgba(20, 184, 166, 0.28);
}
.msg-page .feedback-quick-reply-btn:hover {
    background: rgba(20, 184, 166, 0.18);
}
.msg-page .feedback-product-thumb-placeholder {
    color: var(--msg-ink-400);
    background: var(--msg-surface-2);
}

/* ---- filter tabs (underline style, matches the reference's page-level
   tab bar rather than the pill tabs this used to be) ---- */
.msg-filter-tab {
    padding: 0.5rem 0.15rem;
    margin-right: 1.2rem;
    border-radius: 0;
    border-bottom: 2px solid transparent;
    color: var(--msg-ink-500);
}
.msg-filter-tab:hover {
    background: transparent;
    color: var(--msg-ink-900);
}
.msg-filter-tab.active {
    background: transparent;
    color: #5eead4;
    border-bottom-color: #5eead4;
}
.msg-filter-tab-count {
    padding: 0.05rem 0.45rem;
    border-radius: 999px;
    background: var(--msg-surface-2);
    color: var(--msg-ink-500);
}
.msg-filter-tab.active .msg-filter-tab-count {
    background: rgba(20, 184, 166, 0.18);
    color: #5eead4;
}

/* ---- conversation list ---- */
.msg-skeleton-avatar {
    background: var(--msg-surface-2);
}
.msg-convo-card:hover {
    background: var(--msg-surface-2);
}
.msg-convo-card.active {
    background: rgba(20, 184, 166, 0.12);
    border-left-color: #5eead4;
}
.msg-convo-card.unread .msg-convo-name {
    color: var(--msg-ink-900);
}
.msg-convo-card.unread .msg-convo-snippet {
    color: var(--msg-ink-700);
}
.msg-convo-avatar {
    background: rgba(20, 184, 166, 0.18);
    color: #5eead4;
}
.msg-convo-avatar.lg {
    box-shadow: 0 0 0 4px var(--msg-surface-2), 0 1px 2px rgba(0, 0, 0, 0.2);
}
.msg-convo-name {
    color: var(--msg-ink-700);
}
.msg-convo-time,
.msg-convo-snippet,
.msg-convo-order {
    color: var(--msg-ink-500);
}
.msg-unread-badge {
    background: #5eead4;
    color: var(--msg-surface);
}

/* ---- chat header ---- */
.msg-chat-header {
    border-bottom-color: var(--msg-border-soft);
}
.msg-back-btn {
    color: var(--msg-ink-500);
}
.msg-back-btn:hover {
    background: var(--msg-surface-2);
}
.msg-chat-buyer-name {
    color: var(--msg-ink-900);
}
.msg-order-link {
    color: #9dc2ef;
}
.msg-icon-btn {
    color: var(--msg-ink-500);
}
.msg-icon-btn:hover {
    background: var(--msg-surface-2);
    color: #5eead4;
}
.msg-more-dropdown {
    background: var(--msg-surface);
    border-color: var(--msg-border);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
}
.msg-more-item {
    color: var(--msg-ink-700);
}
.msg-more-item:hover {
    background: var(--msg-surface-2);
}
.msg-more-item.danger {
    color: #f7a49f;
}
.msg-more-item.danger:hover {
    background: rgba(200, 67, 61, 0.16);
}

/* ---- messages viewport ---- */
.msg-viewport {
    background: var(--msg-surface);
}
.msg-skeleton-bubble {
    background: linear-gradient(90deg, var(--msg-surface-2) 25%, var(--msg-border) 37%, var(--msg-surface-2) 63%);
    background-size: 400% 100%;
}
.msg-inline-error {
    color: #f7a49f;
}
.msg-retry-link {
    color: #5eead4;
}
.msg-date-sep span {
    color: var(--msg-ink-500);
    background: var(--msg-surface-2);
}
.msg-bubble.received {
    background: var(--msg-surface-2);
    border-color: var(--msg-border);
    color: var(--msg-ink-900);
    box-shadow: none;
}
.msg-bubble.sent {
    background: #0f766e;
    color: #f2f4f1;
    box-shadow: 0 4px 12px rgba(15, 118, 110, 0.25);
}
.msg-meta {
    color: var(--msg-ink-500);
}
.msg-failed {
    color: #f7a49f;
}
.msg-attachment-chip {
    background: rgba(255, 255, 255, 0.08);
}
.msg-new-messages-btn {
    background: #0f766e;
    color: #f2f4f1;
    box-shadow: 0 8px 20px rgba(15, 118, 110, 0.35);
}

/* ---- composer ---- */
.msg-composer {
    border-top-color: var(--msg-border-soft);
}
.msg-composer-notice {
    color: var(--msg-ink-500);
}
.msg-staged-item {
    background: var(--msg-surface-2);
    border-color: var(--msg-border);
}
.msg-staged-name {
    color: var(--msg-ink-700);
}
.msg-staged-progress {
    color: #5eead4;
}
.msg-staged-error {
    color: #f7a49f;
}
.msg-staged-remove {
    color: var(--msg-ink-500);
}
.msg-staged-remove:hover {
    color: #f7a49f;
}
.msg-composer-box {
    background: var(--msg-surface-2);
    border-color: var(--msg-border);
}
.msg-composer-box:focus-within {
    border-color: #5eead4;
    box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.18);
}
.msg-textarea {
    color: var(--msg-ink-900);
}
.msg-textarea::placeholder {
    color: var(--msg-ink-400);
}
.msg-textarea:disabled {
    color: var(--msg-ink-400);
}
.msg-send-btn {
    background: #0f766e;
    color: #f2f4f1;
    box-shadow: 0 4px 12px rgba(15, 118, 110, 0.25);
}
.msg-send-btn:hover:not(:disabled) {
    background: #115e59;
}
.msg-send-btn:disabled {
    background: var(--msg-surface-2);
    color: var(--msg-ink-400);
}

/* ---- context panel ---- */
.msg-context-header {
    border-bottom-color: var(--msg-border-soft);
}
.msg-context-header h3,
.msg-context-buyer h4 {
    color: var(--msg-ink-900);
}
.msg-context-label,
.msg-context-count,
.msg-context-empty {
    color: var(--msg-ink-500);
}
.msg-order-card {
    background: var(--msg-surface-2);
    border-color: var(--msg-border-soft);
}
.msg-order-card:hover {
    border-color: #5eead4;
}
.msg-order-card-product {
    color: var(--msg-ink-900);
}
.msg-order-card-variant {
    color: var(--msg-ink-500);
}
.msg-order-card-number {
    color: #5eead4;
}
.msg-context-facts span {
    color: var(--msg-ink-500);
}
.msg-context-facts strong {
    color: var(--msg-ink-900);
}
.msg-media-thumb {
    border-color: var(--msg-border-soft);
    background: var(--msg-surface-2);
    color: var(--msg-ink-400);
}

/* ---- context panel: Overview/Order tabs + real order timeline (new —
   these classes don't exist in the shared stylesheet at all, so this is
   a full definition, not just a color override) ---- */
.msg-ctx-tabs {
    display: flex;
    margin: 0 0 1.1rem;
    background: var(--msg-surface-2);
    padding: 0.2rem;
    border-radius: 0.6rem;
}
.msg-ctx-tabs button {
    flex: 1;
    padding: 0.4rem;
    border-radius: 0.45rem;
    border: none;
    background: none;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--msg-ink-500);
    cursor: pointer;
}
.msg-ctx-tabs button.active {
    background: var(--msg-surface);
    color: var(--msg-ink-900);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.msg-track-list {
    display: flex;
    flex-direction: column;
    margin-top: 0.6rem;
}
.msg-track-item {
    display: flex;
    gap: 0.7rem;
    position: relative;
    padding-bottom: 1.1rem;
}
.msg-track-item:last-child {
    padding-bottom: 0;
}
.msg-track-dot-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
}
.msg-track-dot {
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.msg-track-dot.done {
    background: rgba(20, 184, 166, 0.18);
    color: #5eead4;
}
.msg-track-line {
    flex: 1;
    width: 2px;
    background: var(--msg-border);
    margin-top: 0.2rem;
}
.msg-track-title {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--msg-ink-900);
}
.msg-track-meta {
    font-size: 0.71rem;
    color: var(--msg-ink-500);
    margin-top: 0.1rem;
}

/* .msg-page's row (list/chat/context side by side) is now .msg-panels —
   carry forward the same mobile "collapse the gap" behavior the global
   stylesheet still applies to the old .msg-page selector. */
@media (max-width: 860px) {
    .msg-panels {
        gap: 0;
    }
}
</style>