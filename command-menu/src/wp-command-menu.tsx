import { useState, useEffect, useCallback } from "react";
import { createRoot } from "react-dom/client";
import { useHotkeys } from "react-hotkeys-hook";
import { Heading as AriaHeading } from "react-aria-components";
import {
  Plus,
  File06,
  Upload01,
  Clock,
  Home01,
  Settings01,
  Users01,
  Image01,
  MessageSquare01,
  BarChart01,
  Shield01,
  Palette,
  PuzzlePiece01,
  Database01,
  Mail01,
  CreditCard01,
  Calendar,
  Globe01,
  Code01,
  Folder,
  LogOut01,
  User01,
  Heart,
  Download01,
  Link01,
  Edit05,
  BookOpen01,
  LayoutAlt01,
  Menu01,
  ShoppingCart01,
  Package,
  Tag01,
  ChevronRight,
  Tool01,
  FileSearch01,
} from "@untitledui/icons";
import { CommandMenu } from "./components/application/command-menus/command-menu";
import type { CommandDropdownMenuItemProps } from "./components/application/command-menus/base-components/command-menu-item";
import "./globals.css";

// Types for WordPress data passed from PHP
interface WPMenuItem {
  id: string;
  label: string;
  url: string;
  icon?: string;
  parent?: string;
}

interface WPAction {
  id: string;
  label: string;
  url: string;
  icon?: string;
}

interface WPCommandMenuConfig {
  ajaxUrl: string;
  nonce: string;
  menuItems: WPMenuItem[];
  recentPosts: Array<{
    id: number;
    title: string;
    editUrl: string;
    type: string;
  }>;
  quickActions: WPAction[];
  siteActions: WPAction[];
  userActions: WPAction[];
  settingsActions: WPAction[];
  toolsActions: WPAction[];
  hubActions: WPAction[];
  wooActions: WPAction[];
}

declare global {
  interface Window {
    wpCommandMenuConfig: WPCommandMenuConfig;
  }
}

// Map WordPress dashicons to UntitledUI icons
const getIconForDashicon = (dashicon: string) => {
  const map: Record<string, any> = {
    "dashicons-admin-post": File06,
    "dashicons-admin-page": File06,
    "dashicons-admin-media": Image01,
    "dashicons-admin-comments": MessageSquare01,
    "dashicons-admin-users": Users01,
    "dashicons-admin-tools": Settings01,
    "dashicons-admin-settings": Settings01,
    "dashicons-admin-plugins": PuzzlePiece01,
    "dashicons-admin-appearance": Palette,
    "dashicons-dashboard": Home01,
    "dashicons-admin-generic": Folder,
    "dashicons-edit": File06,
    "dashicons-plus": Plus,
    "dashicons-upload": Upload01,
    "dashicons-chart-bar": BarChart01,
    "dashicons-shield": Shield01,
    "dashicons-email": Mail01,
    "dashicons-database": Database01,
    "dashicons-calendar": Calendar,
    "dashicons-admin-site": Globe01,
    "dashicons-editor-code": Code01,
    "dashicons-money-alt": CreditCard01,
  };
  return map[dashicon] || Folder;
};

// Map action icons
const getIconForAction = (iconName: string) => {
  const map: Record<string, any> = {
    "add-01": Plus,
    "upload-01": Upload01,
    "file-01": File06,
    "clock-01": Clock,
    "globe-01": Globe01,
    "palette-01": Palette,
    "layout-01": LayoutAlt01,
    "menu-01": Menu01,
    "user-01": User01,
    "log-out-01": LogOut01,
    "settings-01": Settings01,
    "edit-01": Edit05,
    "book-01": BookOpen01,
    "link-01": Link01,
    "shield-01": Shield01,
    "heart-01": Heart,
    "download-01": Download01,
    "shopping-cart-01": ShoppingCart01,
    "package-01": Package,
    "tag-01": Tag01,
  };
  return map[iconName] || Folder;
};

// Page types for nested navigation
type PageType = "home" | "create" | "settings" | "tools" | "site" | "hub" | "woocommerce" | "navigation";

const WPCommandMenu = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [currentPage, setCurrentPage] = useState<PageType>("home");
  const [inputValue, setInputValue] = useState("");

  const config = window.wpCommandMenuConfig || {
    ajaxUrl: "",
    nonce: "",
    menuItems: [],
    recentPosts: [],
    quickActions: [],
    siteActions: [],
    userActions: [],
    settingsActions: [],
    toolsActions: [],
    hubActions: [],
    wooActions: [],
  };

  // Reset to home when menu closes, and dispatch custom events for hint visibility
  useEffect(() => {
    if (!isOpen) {
      setCurrentPage("home");
      setInputValue("");
    }
    // Dispatch custom event for external listeners (like the keyboard hint)
    window.dispatchEvent(new CustomEvent('wp-command-menu-toggle', { detail: { isOpen } }));
  }, [isOpen]);

  // Prevent arrow keys from scrolling the page when command menu is open
  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        e.preventDefault();
      }
    };

    window.addEventListener('keydown', handleKeyDown, { capture: true });
    return () => window.removeEventListener('keydown', handleKeyDown, { capture: true });
  }, [isOpen]);

  // Open with Cmd+K / Ctrl+K
  useHotkeys("meta+k, ctrl+k", (e) => {
    e.preventDefault();
    setIsOpen(true);
  });

  // Go back with Backspace when input is empty
  const handleBackspace = useCallback(() => {
    if (inputValue === "" && currentPage !== "home") {
      setCurrentPage("home");
    }
  }, [inputValue, currentPage]);

  useHotkeys("backspace", handleBackspace, {
    enableOnFormTags: true,
    enabled: isOpen && inputValue === "" && currentPage !== "home"
  });

  // Helper to map actions to menu items
  const mapActionsToItems = (actions: WPAction[], prefix: string): CommandDropdownMenuItemProps[] =>
    actions.map((action) => ({
      id: `${prefix}-${action.id}`,
      type: "icon" as const,
      label: action.label,
      icon: getIconForAction(action.icon || "add-01"),
      size: "sm" as const,
    }));

  // Build items for each page
  const quickActionItems = mapActionsToItems(config.quickActions, "action");
  const siteActionItems = mapActionsToItems(config.siteActions, "site");
  const userActionItems = mapActionsToItems(config.userActions, "user");
  const settingsActionItems = mapActionsToItems(config.settingsActions, "settings");
  const toolsActionItems = mapActionsToItems(config.toolsActions, "tools");
  const hubActionItems = mapActionsToItems(config.hubActions, "hub");
  const wooActionItems = mapActionsToItems(config.wooActions, "woo");

  const menuItems: CommandDropdownMenuItemProps[] = config.menuItems
    .filter((item) => !item.parent)
    .map((item) => ({
      id: `menu-${item.id}`,
      type: "icon" as const,
      label: item.label,
      icon: getIconForDashicon(item.icon || ""),
      size: "sm" as const,
    }));

  const recentItems: CommandDropdownMenuItemProps[] = config.recentPosts.map((post) => ({
    id: `recent-${post.id}`,
    type: "icon" as const,
    label: post.title || "(no title)",
    description: `Edit ${post.type}`,
    icon: Clock,
    size: "sm" as const,
    stacked: true,
  }));

  // Home page - shows categories and recent
  const homeGroups = [
    // Quick access actions on home
    ...(recentItems.length ? [{ id: "recent", title: "Recent", items: recentItems.slice(0, 5) }] : []),
    // Navigation categories
    {
      id: "categories",
      title: "Go to",
      items: [
        ...(quickActionItems.length ? [{
          id: "nav-create",
          type: "icon" as const,
          label: "Create New...",
          icon: Plus,
          size: "sm" as const,
        }] : []),
        ...(config.menuItems.length ? [{
          id: "nav-navigation",
          type: "icon" as const,
          label: "All Admin Pages...",
          icon: FileSearch01,
          size: "sm" as const,
        }] : []),
        ...(settingsActionItems.length ? [{
          id: "nav-settings",
          type: "icon" as const,
          label: "Settings...",
          icon: Settings01,
          size: "sm" as const,
        }] : []),
        ...(toolsActionItems.length ? [{
          id: "nav-tools",
          type: "icon" as const,
          label: "Tools...",
          icon: Tool01,
          size: "sm" as const,
        }] : []),
        ...(hubActionItems.length ? [{
          id: "nav-hub",
          type: "icon" as const,
          label: "Hub...",
          icon: LayoutAlt01,
          size: "sm" as const,
        }] : []),
        ...(siteActionItems.length ? [{
          id: "nav-site",
          type: "icon" as const,
          label: "Site...",
          icon: Globe01,
          size: "sm" as const,
        }] : []),
        ...(wooActionItems.length ? [{
          id: "nav-woocommerce",
          type: "icon" as const,
          label: "WooCommerce...",
          icon: ShoppingCart01,
          size: "sm" as const,
        }] : []),
      ],
    },
    // Quick user actions always visible
    ...(userActionItems.length ? [{ id: "user", title: "Account", items: userActionItems }] : []),
  ];

  // Get groups based on current page
  const getGroupsForPage = (): { id: string; title: string; items: CommandDropdownMenuItemProps[] }[] => {
    switch (currentPage) {
      case "create":
        return [{ id: "create", title: "Create New", items: quickActionItems }];
      case "settings":
        return [{ id: "settings", title: "Settings", items: settingsActionItems }];
      case "tools":
        return [{ id: "tools", title: "Tools", items: toolsActionItems }];
      case "hub":
        return [{ id: "hub", title: "Hub & Addons", items: hubActionItems }];
      case "site":
        return [{ id: "site", title: "Site", items: siteActionItems }];
      case "woocommerce":
        return [{ id: "woocommerce", title: "WooCommerce", items: wooActionItems }];
      case "navigation":
        return [{ id: "navigation", title: "All Admin Pages", items: menuItems }];
      default:
        return homeGroups;
    }
  };

  // Collect all actions for selection handler
  const allActions = [
    ...config.quickActions.map(a => ({ ...a, prefix: "action" })),
    ...config.siteActions.map(a => ({ ...a, prefix: "site" })),
    ...config.userActions.map(a => ({ ...a, prefix: "user" })),
    ...config.settingsActions.map(a => ({ ...a, prefix: "settings" })),
    ...config.toolsActions.map(a => ({ ...a, prefix: "tools" })),
    ...config.hubActions.map(a => ({ ...a, prefix: "hub" })),
    ...config.wooActions.map(a => ({ ...a, prefix: "woo" })),
  ];

  const handleSelection = (keys: any) => {
    const selectedId = Array.from(keys)[0] as string;
    if (!selectedId) return;

    // Handle navigation to subpages
    if (selectedId === "nav-create") {
      setCurrentPage("create");
      setInputValue("");
      return;
    }
    if (selectedId === "nav-settings") {
      setCurrentPage("settings");
      setInputValue("");
      return;
    }
    if (selectedId === "nav-tools") {
      setCurrentPage("tools");
      setInputValue("");
      return;
    }
    if (selectedId === "nav-hub") {
      setCurrentPage("hub");
      setInputValue("");
      return;
    }
    if (selectedId === "nav-site") {
      setCurrentPage("site");
      setInputValue("");
      return;
    }
    if (selectedId === "nav-woocommerce") {
      setCurrentPage("woocommerce");
      setInputValue("");
      return;
    }
    if (selectedId === "nav-navigation") {
      setCurrentPage("navigation");
      setInputValue("");
      return;
    }

    // Handle menu items
    if (selectedId.startsWith("menu-")) {
      const menuId = selectedId.replace("menu-", "");
      const item = config.menuItems.find((m) => m.id === menuId);
      if (item?.url) window.location.href = item.url;
    }
    // Handle recent posts
    else if (selectedId.startsWith("recent-")) {
      const postId = selectedId.replace("recent-", "");
      const post = config.recentPosts.find((p) => p.id === parseInt(postId));
      if (post?.editUrl) window.location.href = post.editUrl;
    }
    // Handle all action types
    else {
      const action = allActions.find((a) => `${a.prefix}-${a.id}` === selectedId);
      if (action?.url) window.location.href = action.url;
    }

    setIsOpen(false);
  };

  const getPlaceholder = () => {
    switch (currentPage) {
      case "create": return "Search create actions...";
      case "settings": return "Search settings...";
      case "tools": return "Search tools...";
      case "hub": return "Search hub & addons...";
      case "site": return "Search site actions...";
      case "woocommerce": return "Search WooCommerce...";
      case "navigation": return "Search all admin pages...";
      default: return "Type a command or search...";
    }
  };

  return (
    <CommandMenu
      isOpen={isOpen}
      items={getGroupsForPage()}
      onOpenChange={setIsOpen}
      onSelectionChange={handleSelection}
      inputValue={inputValue}
      onInputChange={setInputValue}
      placeholder={getPlaceholder()}
      shortcut="⌘K"
      emptyState={
        <div className="p-6 text-center">
          <p className="text-sm text-tertiary">No results found</p>
          <p className="text-xs text-quaternary mt-1">
            {currentPage !== "home" ? "Press Backspace to go back" : "Try a different search term"}
          </p>
        </div>
      }
    >
      <AriaHeading slot="title" className="sr-only">
        WordPress Command Menu
      </AriaHeading>
      <CommandMenu.Group>
        <CommandMenu.List className="min-h-49">
          {(group) => (
            <CommandMenu.Section title={group.title} {...group}>
              {(item) => <CommandMenu.Item key={item.id} {...item} />}
            </CommandMenu.Section>
          )}
        </CommandMenu.List>
      </CommandMenu.Group>
      <CommandMenu.Footer />
    </CommandMenu>
  );
};

// Mount to WordPress
const mountCommandMenu = () => {
  let container = document.getElementById("wp-command-menu-root");
  if (!container) {
    container = document.createElement("div");
    container.id = "wp-command-menu-root";
    document.body.appendChild(container);
  }

  const root = createRoot(container);
  root.render(<WPCommandMenu />);
};

// Initialize when DOM is ready
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mountCommandMenu);
} else {
  mountCommandMenu();
}

export { WPCommandMenu };
