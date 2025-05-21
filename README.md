WPGraphQL Dynamic Styles ExporterContributors: OJ Smith & The Robot (aka Gemini 2.5 by Google)Requires WordPress Version: 6.1 or higherRequires PHP Version: 7.4 or higherWPGraphQL Requires: 1.8.0 or higherLicense: GPL v2 or laterLicense URI: https://www.gnu.org/licenses/gpl-2.0.htmlDescriptionThe WPGraphQL Dynamic Styles Exporter plugin extends WPGraphQL to provide two crucial sets of CSS necessary for achieving accurate WordPress block editor layouts in a headless frontend application (e.g., a Next.js site).It allows you to fetch:Global Theme Styles: The CSS equivalent to what WordPress generates in the <style id="global-styles-inline-css"> tag. This includes CSS Custom Properties (variables), default HTML element styling, global layout rules (like .is-layout-constrained), and utility classes (.has-color, .has-font-size, etc.) derived from your theme's theme.json and Global Styles settings.Post-Specific Block Support Styles: The dynamic, block-instance-specific CSS (equivalent to <style id="core-block-supports-inline-css">) for the content of a particular post, page, or custom post type. This includes styles for hashed layout classes (e.g., .wp-container-core-group-is-layout-{hash}) and other editor-applied settings.This plugin is particularly useful for headless WordPress developers who want to accurately replicate the Gutenberg/Block Editor visual output in their decoupled frontends.FeaturesProvides a themeGlobalStyles field on the RootQuery in WPGraphQL to fetch global CSS.Allows selection of which parts of global styles to include (variables, presets, styles, base layout styles).Provides a postBlockSupportStyles field on all public post types registered with WPGraphQL (e.g., Post, Page, and Custom Post Types).Dynamically generates CSS for block supports specific to the content of the queried post.Implements caching for postBlockSupportStyles using WordPress Transients to improve performance.Automatically clears the cache for a post's block support styles when that post is updated.DependenciesWordPress: Version 6.1 or higherPHP: Version 7.4 or higherWPGraphQL Plugin: Version 1.8.0 or higher (must be installed and activated)InstallationDownload: Download the plugin ZIP file from the GitHub repository (or clone the repository).Upload to WordPress:In your WordPress admin dashboard, go to Plugins > Add New.Click Upload Plugin.Choose the downloaded ZIP file and click Install Now.Activate: Once installed, click Activate Plugin.How to UseAfter installing and activating the plugin, two new fields will be available in your WPGraphQL schema.1. Fetching Global Theme StylesQuery the themeGlobalStyles field at the root of your GraphQL query. You can specify which parts of the styles you need. It's generally recommended to include all parts for comprehensive styling.Example GraphQL Query for themeGlobalStyles:query GetGlobalThemeStyles {
themeGlobalStyles(
includeVariables: true
includePresets: true
includeStyles: true
includeBaseLayoutStyles: true # For WP 6.5+; on older versions, these are part of 'includeStyles'
)
}
Integration in Headless Frontend (e.g., Next.js layout.tsx):The string returned by themeGlobalStyles should be injected into a <style> tag in the <head> of your application, typically in your main layout component.// Example in a Next.js RootLayout (app/layout.tsx)
// Assume 'themeGlobalStylesString' contains the fetched CSS

<head>
  {/* ... other head elements ... */}
  {themeGlobalStylesString && (
    <style 
      id="wordpress-theme-global-styles-inline" 
      dangerouslySetInnerHTML={{ __html: themeGlobalStylesString }} 
    />
  )}
</head>
2. Fetching Post-Specific Block Support StylesQuery the postBlockSupportStyles field on any public post type (e.g., Post, Page, or your Custom Post Types like "Project").Example GraphQL Query for a "Project" Custom Post Type:query GetSingleProjectWithDynamicStyles($slug: ID!) { # Or $id: ID! if using databaseId
  project(id: $slug, idType: SLUG) { # Adjust 'project' and 'idType' based on your CPT query
    title
    content # Your rendered HTML content
    postBlockSupportStyles # This field provides the dynamic CSS
  }
}
Integration in Headless Frontend (e.g., Next.js Page Component):The string returned by postBlockSupportStyles is specific to the content of that post. It should be injected into a <style> tag on the page or component that renders that post's content.// Example in a Next.js Client Component (e.g., ProjectPageClientContent.tsx)
// Assume 'postBlockSupportStylesString' is passed as a prop

<>
{postBlockSupportStylesString && (
<style
id="wordpress-post-block-support-styles"
dangerouslySetInnerHTML={{ __html: postBlockSupportStylesString }}
/>
)}
{/_ Then render your WordPress content, e.g., using dangerouslySetInnerHTML _/}

  <div dangerouslySetInnerHTML={{ __html: wordpressContent }} />
</>
CachingThe postBlockSupportStyles field uses the WordPress Transients API to cache the generated CSS for each post. The default expiration is 12 hours.The cache for a specific post's styles is automatically cleared when that post is updated.Known Limitations / Important NotesPerformance of postBlockSupportStyles: The first time postBlockSupportStyles is requested for a post, the CSS is generated dynamically by rendering the post's blocks internally. This can add some processing overhead to that initial request, especially for very long or complex posts. Subsequent requests for the same post (within the cache expiration period) will be served from the cache and will be much faster.Dependency on Block Content: The CSS generated by postBlockSupportStyles is entirely dependent on the blocks actually present in the post's content and their specific settings.Completeness: This plugin aims to capture the CSS from global-styles-inline-css and core-block-supports-inline-css. You will still need to include the standard WordPress block library CSS files (e.g., from @wordpress/block-library/build-style/common.css, style.css, theme.css) and your theme's static style.css in your headless frontend for complete styling.ContributingContributions are welcome! If you find issues or have ideas for improvements, please open an issue or submit a pull request on the GitHub repository. (Link to be added once repository is created).
